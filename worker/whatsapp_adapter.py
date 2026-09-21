"""
WhatsApp Web Playwright Adapter Layer
Encapsulates all browser automation selectors and interaction logic.
"""

import os
import time
import logging
import urllib.parse
from datetime import datetime
from typing import Tuple, Optional

logger = logging.getLogger("WhatsAppAdapter")

class WhatsAppAdapter:
    # Key UI Selectors
    QR_SELECTORS = [
        'canvas[aria-label*="Scan"]',
        'div[data-ref]',
        '[data-testid="qrcode"]',
        'div[data-testid="intro-qr-wrapper"]'
    ]
    
    CONNECTED_SELECTORS = [
        '#pane-side',
        '[data-testid="chat-list"]',
        'header[data-testid="chatlist-header"]',
        'div[data-testid="default-user"]',
        'div[role="navigation"]'
    ]

    COMPOSER_SELECTORS = [
        'footer div[contenteditable="true"][data-tab="10"]',
        'footer div[contenteditable="true"]',
        'div[data-testid="conversation-compose-box-input"]'
    ]

    SEND_BUTTON_SELECTORS = [
        'button[data-testid="send"]',
        'button[aria-label="Send"]',
        'span[data-icon="send"]',
        'button[data-tab="11"]'
    ]

    INVALID_NUMBER_SELECTORS = [
        'div[data-animate-modal-popup="true"]',
        'div[role="dialog"]'
    ]

    ATTACH_BUTTON_SELECTORS = [
        'button[data-testid="attach-menu-plus"]',
        'button[title="Attach"]',
        'span[data-icon="plus"]'
    ]

    MEDIA_SEND_BUTTON_SELECTORS = [
        'span[data-icon="send"]',
        'div[role="button"][aria-label="Send"]',
        'button[data-testid="send"]'
    ]

    def __init__(self, page, diagnostics_dir: str = "diagnostics"):
        self.page = page
        self.diagnostics_dir = diagnostics_dir
        os.makedirs(self.diagnostics_dir, exist_ok=True)

    def detect_login_state(self) -> str:
        """
        Determines current state of WhatsApp Web without triggering page reloads.
        Returns: 'connected', 'qr_ready', 'logged_out', or 'loading'
        """
        current_url = self.page.url

        if "post_logout=1" in current_url:
            return "logged_out"

        # Check for chat pane (connected)
        for sel in self.CONNECTED_SELECTORS:
            try:
                el = self.page.locator(sel).first
                if el.is_visible(timeout=500):
                    return "connected"
            except Exception:
                pass

        # Check for QR code
        for sel in self.QR_SELECTORS:
            try:
                el = self.page.locator(sel).first
                if el.is_visible(timeout=500):
                    return "qr_ready"
            except Exception:
                pass

        return "loading"

    def wait_for_connection(self, timeout_sec: int = 45) -> str:
        """
        Waits until WhatsApp Web is either connected or shows QR code.
        """
        start = time.time()
        logger.info("Checking WhatsApp Web state...")

        while time.time() - start < timeout_sec:
            state = self.detect_login_state()
            if state in ("connected", "qr_ready", "logged_out"):
                return state
            time.sleep(1.5)

        return "timeout"

    def capture_diagnostics(self, context_name: str):
        """
        Captures safe diagnostic screenshot and log context.
        """
        try:
            timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
            filename = f"diag_{context_name}_{timestamp}.png"
            path = os.path.join(self.diagnostics_dir, filename)
            self.page.screenshot(path=path)
            logger.warning(f"Saved diagnostic screenshot: {path} (Page title: '{self.page.title()}', URL: {self.page.url})")
        except Exception as e:
            logger.error(f"Failed to capture diagnostics: {e}")

    def send_message(self, phone: str, text: str, media_path: Optional[str] = None) -> Tuple[bool, Optional[str]]:
        """
        Sends a message to a specific phone number with optional media attachment.
        Returns: (success: bool, error_message: Optional[str])
        """
        # Ensure phone has no plus sign or spaces
        clean_phone = "".join(filter(str.isdigit, phone))
        encoded_text = urllib.parse.quote(text)
        send_url = f"https://web.whatsapp.com/send?phone={clean_phone}&text={encoded_text}"

        logger.info(f"Navigating to conversation with recipient ending in ...{clean_phone[-4:]}")
        try:
            self.page.goto(send_url, wait_until="domcontentloaded", timeout=45000)
        except Exception as e:
            self.capture_diagnostics("goto_failed")
            return False, f"Navigation failed: {str(e)}"

        # Check for post-logout redirect
        if "post_logout=1" in self.page.url:
            return False, "WhatsApp session logged out unexpectedly"

        # Check for invalid phone dialog popup
        start_wait = time.time()
        composer_found = False

        while time.time() - start_wait < 35:
            # Check for invalid number dialog
            for sel in self.INVALID_NUMBER_SELECTORS:
                try:
                    popup = self.page.locator(sel).first
                    if popup.is_visible(timeout=300):
                        popup_text = popup.inner_text().lower()
                        if "invalid" in popup_text or "phone number" in popup_text or "not registered" in popup_text:
                            # Dismiss popup
                            try:
                                btn = popup.locator("button").first
                                if btn.is_visible():
                                    btn.click()
                            except Exception:
                                pass
                            return False, f"Phone number {clean_phone} is not registered on WhatsApp or is invalid"
                except Exception:
                    pass

            # Check for send button or composer
            for sel in self.SEND_BUTTON_SELECTORS:
                try:
                    btn = self.page.locator(sel).first
                    if btn.is_visible(timeout=300):
                        composer_found = True
                        break
                except Exception:
                    pass

            if composer_found:
                break

            time.sleep(1)

        if not composer_found:
            self.capture_diagnostics("composer_not_found")
            return False, "Timed out waiting for WhatsApp composer or send button"

        # Handle media attachment if provided
        if media_path and os.path.exists(media_path):
            try:
                logger.info(f"Attaching media file: {os.path.basename(media_path)}")
                # Click attach button
                attach_btn = None
                for sel in self.ATTACH_BUTTON_SELECTORS:
                    try:
                        b = self.page.locator(sel).first
                        if b.is_visible(timeout=1000):
                            attach_btn = b
                            break
                    except Exception:
                        pass

                if attach_btn:
                    # Set up file chooser listener
                    with self.page.expect_file_chooser(timeout=10000) as fc_info:
                        attach_btn.click()
                        # Try clicking document or photos button if popup appears
                        try:
                            item_btn = self.page.locator('button[aria-label*="document"], button[aria-label*="photo"], [data-testid="attach-image"]').first
                            if item_btn.is_visible(timeout=1500):
                                item_btn.click()
                        except Exception:
                            pass
                    
                    file_chooser = fc_info.value
                    file_chooser.set_files(media_path)
                    time.sleep(2) # Allow preview rendering
                else:
                    # Fallback: find any file input element
                    file_input = self.page.locator('input[type="file"]').first
                    file_input.set_input_files(media_path)
                    time.sleep(2)
            except Exception as e:
                logger.warning(f"Media attach step warning: {e}. Attempting text fallback.")

        # Click send button
        try:
            sent_clicked = False
            for sel in self.SEND_BUTTON_SELECTORS + self.MEDIA_SEND_BUTTON_SELECTORS:
                try:
                    btn = self.page.locator(sel).first
                    if btn.is_visible(timeout=1000):
                        btn.click()
                        sent_clicked = True
                        break
                except Exception:
                    pass

            if not sent_clicked:
                # Press Enter in composer
                for sel in self.COMPOSER_SELECTORS:
                    try:
                        composer = self.page.locator(sel).first
                        if composer.is_visible(timeout=1000):
                            composer.press("Enter")
                            sent_clicked = True
                            break
                    except Exception:
                        pass

            if not sent_clicked:
                self.capture_diagnostics("send_click_failed")
                return False, "Could not click send button or submit message"

            # Allow message transmission delay
            time.sleep(3)
            logger.info("Message dispatched successfully through WhatsApp Web.")
            return True, None

        except Exception as e:
            self.capture_diagnostics("send_error")
            return False, f"Send message execution error: {str(e)}"
