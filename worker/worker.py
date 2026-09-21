"""
WhatsApp Automation Worker Daemon
Connects to PHP REST API via Bearer Token and manages persistent Playwright session.
"""

import os
import sys
import time
import random
import configparser
import threading
import logging
import platform
import tempfile
import requests
from typing import Optional
from playwright.sync_api import sync_playwright
from whatsapp_adapter import WhatsAppAdapter

WORKER_DIR = os.path.dirname(os.path.abspath(__file__))

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] [%(name)s] %(message)s",
    handlers=[
        logging.StreamHandler(sys.stdout),
        logging.FileHandler(os.path.join(WORKER_DIR, "worker.log"), encoding="utf-8")
    ]
)
logger = logging.getLogger("WorkerDaemon")

WORKER_VERSION = "1.0.0"

class WhatsAppWorker:
    def __init__(self, config_path: Optional[str] = None):
        config_path = config_path or "config.ini"
        self.config_path = config_path if os.path.isabs(config_path) else os.path.join(WORKER_DIR, config_path)
        self.load_config()
        self.running = False
        self.whatsapp_status = "disconnected"
        self.adapter: Optional[WhatsAppAdapter] = None
        self.browser_context = None
        self.page = None
        self.sent_in_current_batch = 0

    def load_config(self):
        config = configparser.ConfigParser()
        if not os.path.exists(self.config_path):
            example_path = os.path.join(WORKER_DIR, "config.example.ini")
            logger.error(f"Configuration file not found: {self.config_path}. Copying config.example.ini...")
            if os.path.exists(example_path):
                import shutil
                shutil.copy(example_path, self.config_path)

        config.read(self.config_path)
        self.base_url = config.get("api", "base_url", fallback="http://localhost:8080/web/api").rstrip("/")
        self.token = config.get("worker", "token", fallback="").strip()
        self.poll_interval = config.getint("worker", "poll_interval_seconds", fallback=5)
        self.heartbeat_interval = config.getint("worker", "heartbeat_interval_seconds", fallback=15)
        self.channel = config.get("browser", "channel", fallback="chrome")
        self.headless = config.getboolean("browser", "headless", fallback=False)
        profile_dir = config.get("browser", "profile_dir", fallback="whatsapp_profile")
        self.profile_dir = profile_dir if os.path.isabs(profile_dir) else os.path.join(WORKER_DIR, profile_dir)

        # Default pacing settings (synced dynamically from server heartbeat)
        self.min_delay = 5
        self.max_delay = 15
        self.max_batch_size = 20
        self.pause_between_batches = 60

    def get_api_headers(self):
        return {
            "Authorization": f"Bearer {self.token}",
            "Accept": "application/json",
            "User-Agent": f"WhatsAppWorker/{WORKER_VERSION} ({platform.system()} {platform.release()})"
        }

    def heartbeat_loop(self):
        """Background thread sending periodic heartbeat to PHP API"""
        logger.info("Starting background heartbeat thread...")
        while self.running:
            try:
                payload = {
                    "whatsapp_status": self.whatsapp_status,
                    "os_info": f"{platform.system()} {platform.release()}",
                    "browser_info": f"Playwright ({self.channel})",
                    "python_version": platform.python_version(),
                    "worker_version": WORKER_VERSION
                }

                resp = requests.post(
                    f"{self.base_url}/worker/heartbeat.php",
                    json=payload,
                    headers=self.get_api_headers(),
                    timeout=10
                )

                if resp.status_code == 200:
                    data = resp.json()
                    if data.get("success"):
                        # Update pacing settings from server
                        settings = data.get("settings", {})
                        self.min_delay = settings.get("min_delay_seconds", self.min_delay)
                        self.max_delay = settings.get("max_delay_seconds", self.max_delay)
                        self.max_batch_size = settings.get("max_messages_per_batch", self.max_batch_size)
                        self.pause_between_batches = settings.get("pause_between_batches_seconds", self.pause_between_batches)
                elif resp.status_code == 401:
                    logger.error("Bearer token rejected by API server! Please update token in config.ini.")
                elif resp.status_code == 403:
                    logger.warning("Worker has been disabled on the web control panel.")

            except Exception as e:
                logger.warning(f"Heartbeat transmission error: {e}")

            time.sleep(self.heartbeat_interval)

    def download_media(self, media_id: int, original_name: str) -> Optional[str]:
        """Downloads media attachment securely from authenticated PHP API"""
        try:
            url = f"{self.base_url}/worker/download_media.php?id={media_id}"
            resp = requests.get(url, headers=self.get_api_headers(), stream=True, timeout=30)
            if resp.status_code == 200:
                ext = os.path.splitext(original_name)[1]
                temp_file = tempfile.NamedTemporaryFile(delete=False, suffix=ext)
                for chunk in resp.iter_content(chunk_size=8192):
                    temp_file.write(chunk)
                temp_file.close()
                return temp_file.name
            else:
                logger.error(f"Failed to download media #{media_id}: HTTP {resp.status_code}")
                return None
        except Exception as e:
            logger.error(f"Media download exception: {e}")
            return None

    def claim_job(self) -> Optional[dict]:
        """Claims next pending job atomically from the PHP API"""
        try:
            resp = requests.post(
                f"{self.base_url}/worker/claim_job.php",
                headers=self.get_api_headers(),
                timeout=12
            )
            if resp.status_code == 200:
                data = resp.json()
                if data.get("success") and data.get("job"):
                    return data["job"]
            elif resp.status_code == 401:
                logger.error("Unauthorized claim attempt. Verify bearer token.")
            return None
        except Exception as e:
            logger.warning(f"Error while polling claim_job: {e}")
            return None

    def report_job(self, job_id: int, result: str, error_msg: Optional[str] = None):
        """Reports job completion or failure to PHP API"""
        try:
            payload = {
                "job_id": job_id,
                "result": result,
                "error_message": error_msg
            }
            resp = requests.post(
                f"{self.base_url}/worker/report_job.php",
                json=payload,
                headers=self.get_api_headers(),
                timeout=12
            )
            if resp.status_code == 200:
                logger.info(f"Reported Job #{job_id} as '{result}' successfully.")
            else:
                logger.warning(f"Job #{job_id} report returned HTTP {resp.status_code}: {resp.text}")
        except Exception as e:
            logger.error(f"Failed to transmit job report for #{job_id}: {e}")

    def run(self):
        if not self.token:
            logger.error("No worker token found in config.ini! Register worker on web panel first.")
            return

        self.running = True

        # Start heartbeat thread
        hb_thread = threading.Thread(target=self.heartbeat_loop, daemon=True)
        hb_thread.start()

        with sync_playwright() as p:
            logger.info(f"Initializing persistent browser context: profile='{self.profile_dir}'")
            
            # Browser launch args
            launch_args = [
                "--disable-blink-features=AutomationControlled",
                "--no-sandbox",
                "--disable-infobars"
            ]

            try:
                # Try preferred channel
                self.browser_context = p.chromium.launch_persistent_context(
                    user_data_dir=self.profile_dir,
                    channel=self.channel if self.channel in ("chrome", "msedge") else None,
                    headless=self.headless,
                    args=launch_args,
                    viewport={"width": 1280, "height": 800}
                )
            except Exception as e:
                logger.warning(f"Failed to launch with channel '{self.channel}': {e}. Falling back to default Chromium.")
                self.browser_context = p.chromium.launch_persistent_context(
                    user_data_dir=self.profile_dir,
                    headless=self.headless,
                    args=launch_args,
                    viewport={"width": 1280, "height": 800}
                )

            self.page = self.browser_context.pages[0] if self.browser_context.pages else self.browser_context.new_page()
            self.adapter = WhatsAppAdapter(self.page)

            logger.info("Navigating to WhatsApp Web...")
            self.page.goto("https://web.whatsapp.com", wait_until="domcontentloaded", timeout=60000)

            # Wait for connection or QR
            state = self.adapter.wait_for_connection(timeout_sec=45)
            self.whatsapp_status = state
            logger.info(f"Initial WhatsApp Web state: {state}")

            if state == "qr_ready":
                logger.warning("=" * 60)
                logger.warning("WhatsApp authentication required!")
                logger.warning("Please run '2_LINK_WHATSAPP.bat' to scan QR code.")
                logger.warning("=" * 60)

            # Main Polling Loop
            try:
                while self.running:
                    # Verify connection state periodically
                    current_state = self.adapter.detect_login_state()
                    self.whatsapp_status = current_state

                    if current_state != "connected":
                        logger.info(f"WhatsApp is not connected (current state: {current_state}). Waiting...")
                        time.sleep(self.poll_interval)
                        continue

                    # Check batch limits
                    if self.max_batch_size > 0 and self.sent_in_current_batch >= self.max_batch_size:
                        logger.info(f"Reached batch limit of {self.max_batch_size} messages. Taking a scheduled pause of {self.pause_between_batches}s...")
                        time.sleep(self.pause_between_batches)
                        self.sent_in_current_batch = 0

                    # Claim job
                    job = self.claim_job()
                    if not job:
                        time.sleep(self.poll_interval)
                        continue

                    job_id = job["id"]
                    phone = job["phone"]
                    text = job["rendered_message"]
                    media_info = job.get("media")

                    logger.info(f"Executing Job #{job_id} for recipient ...{phone[-4:]}")

                    # Download media if attached
                    media_temp_path = None
                    if media_info and "id" in media_info:
                        media_temp_path = self.download_media(media_info["id"], media_info.get("original_name", "attachment"))

                    # Send via WhatsApp Web
                    success, error_msg = self.adapter.send_message(phone, text, media_temp_path)

                    # Clean up temp file
                    if media_temp_path and os.path.exists(media_temp_path):
                        try:
                            os.remove(media_temp_path)
                        except Exception:
                            pass

                    # Report result
                    if success:
                        self.report_job(job_id, "sent")
                        self.sent_in_current_batch += 1
                    else:
                        logger.error(f"Job #{job_id} failed: {error_msg}")
                        self.report_job(job_id, "failed", error_msg)

                    # Apply random delay
                    delay = random.uniform(self.min_delay, self.max_delay)
                    logger.info(f"Pacing delay: sleeping {delay:.1f}s before next operation...")
                    time.sleep(delay)

            except KeyboardInterrupt:
                logger.info("Shutdown signal received (Ctrl+C). Exiting cleanly...")
            finally:
                self.running = False
                if self.browser_context:
                    self.browser_context.close()
                logger.info("Worker process terminated.")

if __name__ == "__main__":
    worker = WhatsAppWorker()
    worker.run()
