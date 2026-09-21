"""
Interactive WhatsApp Web Linking Helper
Launches persistent Playwright session in headful mode and monitors QR scan.
"""

import os
import sys
import time
import configparser
from playwright.sync_api import sync_playwright

def link_whatsapp():
    config = configparser.ConfigParser()
    config.read("config.ini")
    profile_dir = os.path.abspath(config.get("browser", "profile_dir", fallback="whatsapp_profile"))
    channel = config.get("browser", "channel", fallback="chrome")

    print("=" * 65)
    print("WHATSAPP WEB ACCOUNT LINKING SESSION")
    print("=" * 65)
    print(f"Profile directory: {profile_dir}")
    print("Opening browser window... Please scan the QR code with WhatsApp on your phone.")
    print("Settings -> Linked Devices -> Link a Device")
    print("=" * 65)

    with sync_playwright() as p:
        args = [
            "--disable-blink-features=AutomationControlled",
            "--no-sandbox"
        ]

        try:
            context = p.chromium.launch_persistent_context(
                user_data_dir=profile_dir,
                channel=channel if channel in ("chrome", "msedge") else None,
                headless=False,
                args=args,
                viewport={"width": 1100, "height": 750}
            )
        except Exception:
            context = p.chromium.launch_persistent_context(
                user_data_dir=profile_dir,
                headless=False,
                args=args,
                viewport={"width": 1100, "height": 750}
            )

        page = context.pages[0] if context.pages else context.new_page()
        page.goto("https://web.whatsapp.com", wait_until="domcontentloaded", timeout=60000)

        print("Waiting for login authorization (will auto-detect when chat list loads)...")

        # Wait for chat list pane indicating active authenticated session
        logged_in = False
        while not logged_in:
            try:
                chat_pane = page.locator('#pane-side, [data-testid="chat-list"]').first
                if chat_pane.is_visible(timeout=2000):
                    logged_in = True
                    break
            except Exception:
                pass
            time.sleep(1)

        print("\n" + "=" * 65)
        print("SUCCESS: WhatsApp Web session linked and saved to persistent profile!")
        print("You can now close the browser window and start '3_RUN_WORKER.bat'.")
        print("=" * 65)

        time.sleep(5)
        context.close()

if __name__ == "__main__":
    link_whatsapp()
