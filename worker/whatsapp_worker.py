"""
WhatsApp Bot Control Panel - Python Worker
Connects to WhatsApp Web via Playwright browser automation
Communicates with PHP API using Bearer token authentication

Architecture:
1. Load configuration
2. Authenticate with API
3. Launch persistent browser
4. Check WhatsApp Web connection
5. Poll for jobs
6. Send messages
7. Report results
8. Handle errors and recovery
"""

import asyncio
import logging
import os
import json
import sys
import time
from typing import Optional, Dict, Any
from datetime import datetime
import requests
from pathlib import Path

# Try to import playwright (may not be installed)
try:
    from playwright.async_api import async_playwright, Page, Browser, BrowserContext
except ImportError:
    print("ERROR: Playwright not installed. Run: pip install playwright")
    sys.exit(1)

# ============================================================================
# CONFIGURATION
# ============================================================================

class Config:
    """Load and manage worker configuration"""
    
    def __init__(self, config_file: str = 'worker_config.json'):
        self.config_file = config_file
        self.config = {}
        self.load()
    
    def load(self):
        """Load configuration from file"""
        if not os.path.exists(self.config_file):
            print(f"ERROR: Configuration file not found: {self.config_file}")
            print("Create worker_config.json with:")
            print(json.dumps({
                "api_url": "http://localhost/api/v1",
                "worker_token": "your-worker-token-here",
                "browser_type": "chromium",
                "headless": False,
                "profile_path": "./whatsapp_session",
                "log_file": "worker.log",
                "heartbeat_interval": 30,
                "job_poll_interval": 5,
                "message_delay_min": 5,
                "message_delay_max": 15,
                "max_retries": 3
            }, indent=2))
            sys.exit(1)
        
        with open(self.config_file, 'r') as f:
            self.config = json.load(f)
    
    def get(self, key: str, default=None):
        """Get config value"""
        return self.config.get(key, default)

# ============================================================================
# LOGGING
# ============================================================================

class WorkerLogger:
    """Centralized logging for worker"""
    
    def __init__(self, log_file: str):
        self.log_file = log_file
        self._setup_logging()
    
    def _setup_logging(self):
        """Setup logging handlers"""
        log_dir = os.path.dirname(self.log_file) or '.'
        os.makedirs(log_dir, exist_ok=True)
        
        self.logger = logging.getLogger('whatsapp_worker')
        self.logger.setLevel(logging.DEBUG)
        
        # File handler
        fh = logging.FileHandler(self.log_file)
        fh.setLevel(logging.DEBUG)
        
        # Console handler
        ch = logging.StreamHandler()
        ch.setLevel(logging.INFO)
        
        # Formatter
        formatter = logging.Formatter(
            '%(asctime)s - %(name)s - %(levelname)s - %(message)s'
        )
        fh.setFormatter(formatter)
        ch.setFormatter(formatter)
        
        self.logger.addHandler(fh)
        self.logger.addHandler(ch)
    
    def debug(self, msg: str):
        self.logger.debug(msg)
    
    def info(self, msg: str):
        self.logger.info(msg)
    
    def warning(self, msg: str):
        self.logger.warning(msg)
    
    def error(self, msg: str):
        self.logger.error(msg)
    
    def critical(self, msg: str):
        self.logger.critical(msg)

# ============================================================================
# API CLIENT
# ============================================================================

class APIClient:
    """WhatsApp Bot API Client"""
    
    def __init__(self, base_url: str, worker_token: str, logger: WorkerLogger):
        self.base_url = base_url.rstrip('/')
        self.worker_token = worker_token
        self.logger = logger
        self.session = requests.Session()
        self.session.headers.update({
            'Authorization': f'Bearer {worker_token}',
            'Content-Type': 'application/json',
            'User-Agent': 'WhatsAppWorker/1.0'
        })
    
    def _request(self, method: str, endpoint: str, data: dict = None, timeout: int = 30) -> Optional[Dict]:
        """Make API request"""
        url = f"{self.base_url}/{endpoint.lstrip('/')}"
        
        try:
            if method.upper() == 'GET':
                response = self.session.get(url, timeout=timeout)
            elif method.upper() == 'POST':
                response = self.session.post(url, json=data, timeout=timeout)
            else:
                self.logger.error(f"Unsupported HTTP method: {method}")
                return None
            
            response.raise_for_status()
            return response.json()
            
        except requests.exceptions.Timeout:
            self.logger.error(f"API timeout: {endpoint}")
            return None
        except requests.exceptions.ConnectionError:
            self.logger.error(f"API connection error: {endpoint}")
            return None
        except requests.exceptions.HTTPError as e:
            self.logger.error(f"API HTTP error: {e.response.status_code} {endpoint}")
            return None
        except Exception as e:
            self.logger.error(f"API request failed: {e}")
            return None
    
    def heartbeat(self, is_whatsapp_connected: bool, browser_type: str = 'chromium',
                  python_version: str = '', worker_version: str = '1.0') -> bool:
        """Send worker heartbeat"""
        data = {
            'is_whatsapp_connected': is_whatsapp_connected,
            'browser_type': browser_type,
            'python_version': python_version,
            'worker_version': worker_version
        }
        result = self._request('POST', 'worker/heartbeat', data)
        return result and result.get('success', False)
    
    def get_status(self) -> Optional[Dict]:
        """Get worker status"""
        return self._request('GET', 'worker/status')
    
    def claim_job(self) -> Optional[Dict]:
        """Claim next job from queue"""
        result = self._request('POST', 'jobs/claim', {})
        if result and result.get('success'):
            return result.get('data')
        return None
    
    def report_job_result(self, job_id: int, status: str, error: str = '') -> bool:
        """Report job result"""
        data = {
            'job_id': job_id,
            'status': status,
            'error': error
        }
        result = self._request('POST', 'jobs/report', data)
        return result and result.get('success', False)
    
    def download_media(self, media_id: int, output_path: str) -> bool:
        """Download media file"""
        url = f"{self.base_url}/media/download?media_id={media_id}"
        try:
            response = self.session.get(url, timeout=60, stream=True)
            response.raise_for_status()
            
            os.makedirs(os.path.dirname(output_path) or '.', exist_ok=True)
            with open(output_path, 'wb') as f:
                for chunk in response.iter_content(chunk_size=8192):
                    if chunk:
                        f.write(chunk)
            return True
        except Exception as e:
            self.logger.error(f"Media download failed: {e}")
            return False
    
    def send_diagnostics(self, current_url: str = '', page_title: str = '', 
                        error_message: str = '', browser_log: str = '') -> bool:
        """Send diagnostic information"""
        data = {
            'current_url': current_url,
            'page_title': page_title,
            'error_message': error_message,
            'browser_log': browser_log[:1000]
        }
        result = self._request('POST', 'worker/diagnostics', data)
        return result and result.get('success', False)

# ============================================================================
# WHATSAPP WEB AUTOMATION
# ============================================================================

class WhatsAppAutomation:
    """WhatsApp Web browser automation wrapper"""
    
    # CSS Selectors for WhatsApp Web UI (fragile - these may change)
    SELECTORS = {
        'login_screen': '[data-testid="login"]',
        'qr_code': 'canvas',
        'main_chat_list': '[data-testid="ChatList-PrivateChats"]',
        'search_input': '[data-testid="search-input"]',
        'chat_item': '[data-testid="ChatItemContent"]',
        'message_input': '[data-testid="msg-input"]',
        'send_button': '[data-testid="SendButton"]',
        'connected_indicator': '[data-testid="connected-icon"]',
    }
    
    def __init__(self, page: Page, logger: WorkerLogger):
        self.page = page
        self.logger = logger
    
    async def is_login_required(self) -> bool:
        """Check if QR authentication is required"""
        try:
            login_elem = await self.page.query_selector(self.SELECTORS['login_screen'])
            return login_elem is not None
        except Exception as e:
            self.logger.debug(f"Login check error: {e}")
            return False
    
    async def wait_for_qr(self, timeout_ms: int = 300000) -> bool:
        """Wait for QR code during authentication"""
        try:
            qr_elem = await self.page.wait_for_selector(self.SELECTORS['qr_code'], timeout=timeout_ms)
            self.logger.info("QR code displayed - scan with your phone")
            return True
        except Exception as e:
            self.logger.error(f"QR wait failed: {e}")
            return False
    
    async def is_connected(self) -> bool:
        """Check if WhatsApp Web is connected"""
        try:
            # Navigate to WhatsApp Web
            await self.page.goto('https://web.whatsapp.com', wait_until='domcontentloaded', timeout=10000)
            
            # Check login requirement
            if await self.is_login_required():
                self.logger.warning("WhatsApp requires login - authentication_required")
                return False
            
            # Check if chat list is visible
            await self.page.wait_for_selector(self.SELECTORS['main_chat_list'], timeout=5000)
            
            self.logger.info("WhatsApp Web connected successfully")
            return True
            
        except Exception as e:
            self.logger.error(f"Connection check failed: {e}")
            return False
    
    async def send_message(self, phone_number: str, message_text: str, 
                          media_path: Optional[str] = None) -> bool:
        """Send message to contact via WhatsApp Web"""
        try:
            # Navigate to direct message URL (more reliable than search)
            send_url = f"https://web.whatsapp.com/send?phone={phone_number}"
            
            self.logger.info(f"Navigating to chat with {phone_number}")
            await self.page.goto(send_url, wait_until='domcontentloaded', timeout=15000)
            
            # Wait for message input to be available
            await self.page.wait_for_selector(self.SELECTORS['message_input'], timeout=10000)
            
            # If media attachment, upload it
            if media_path and os.path.exists(media_path):
                self.logger.info(f"Attaching media: {media_path}")
                # Note: Implement media attachment logic here
                # This would involve finding the attachment button and uploading
            
            # Type and send message
            msg_input = await self.page.query_selector(self.SELECTORS['message_input'])
            await msg_input.fill(message_text)
            
            # Send message
            send_btn = await self.page.query_selector(self.SELECTORS['send_button'])
            await send_btn.click()
            
            # Wait for send confirmation (wait for message input to clear)
            await self.page.wait_for_function(
                "document.querySelector('[data-testid=\"msg-input\"]').value === ''",
                timeout=5000
            )
            
            self.logger.info(f"Message sent to {phone_number}")
            return True
            
        except Exception as e:
            self.logger.error(f"Message send failed for {phone_number}: {e}")
            return False

# ============================================================================
# WORKER MAIN CLASS
# ============================================================================

class WhatsAppWorker:
    """Main worker class - orchestrates all operations"""
    
    def __init__(self, config: Config, logger: WorkerLogger):
        self.config = config
        self.logger = logger
        self.api = APIClient(
            config.get('api_url', 'http://localhost/api/v1'),
            config.get('worker_token', ''),
            logger
        )
        self.browser: Optional[Browser] = None
        self.context: Optional[BrowserContext] = None
        self.page: Optional[Page] = None
        self.whatsapp: Optional[WhatsAppAutomation] = None
        self.is_running = True
        self.is_whatsapp_connected = False
    
    async def initialize(self):
        """Initialize browser and WhatsApp connection"""
        self.logger.info("Initializing WhatsApp Worker...")
        
        try:
            playwright = await async_playwright().start()
            
            browser_type = self.config.get('browser_type', 'chromium')
            
            # Launch browser with persistent profile
            profile_path = self.config.get('profile_path', './whatsapp_session')
            os.makedirs(profile_path, exist_ok=True)
            
            if browser_type == 'chrome':
                browser = await playwright.chromium.launch(headless=False)
            elif browser_type == 'edge':
                browser = await playwright.chromium.launch_persistent_context(
                    profile_path,
                    headless=False,
                    channel='msedge'
                )
            else:  # chromium default
                browser = await playwright.chromium.launch(headless=False)
            
            self.browser = browser
            
            # Create context with persistent session
            self.context = await browser.new_context()
            self.page = await self.context.new_page()
            self.whatsapp = WhatsAppAutomation(self.page, self.logger)
            
            # Check WhatsApp connection
            self.is_whatsapp_connected = await self.whatsapp.is_connected()
            
            if not self.is_whatsapp_connected:
                self.logger.warning("WhatsApp Web not connected - waiting for authentication")
                if await self.whatsapp.wait_for_qr():
                    self.logger.info("Please scan QR code with your phone...")
                    # Wait for connection (up to 5 minutes)
                    for i in range(30):
                        await asyncio.sleep(10)
                        if await self.whatsapp.is_connected():
                            self.is_whatsapp_connected = True
                            break
            
            self.logger.info("Worker initialized successfully")
            return True
            
        except Exception as e:
            self.logger.critical(f"Initialization failed: {e}")
            return False
    
    async def send_heartbeat(self):
        """Send periodic heartbeat to API"""
        while self.is_running:
            try:
                python_ver = f"{sys.version_info.major}.{sys.version_info.minor}.{sys.version_info.micro}"
                self.api.heartbeat(
                    self.is_whatsapp_connected,
                    self.config.get('browser_type', 'chromium'),
                    python_ver,
                    '1.0'
                )
            except Exception as e:
                self.logger.error(f"Heartbeat failed: {e}")
            
            await asyncio.sleep(self.config.get('heartbeat_interval', 30))
    
    async def process_jobs(self):
        """Main job processing loop"""
        consecutive_failures = 0
        
        while self.is_running:
            try:
                # Claim job
                job = self.api.claim_job()
                
                if not job:
                    # No jobs available
                    consecutive_failures = 0
                    await asyncio.sleep(self.config.get('job_poll_interval', 5))
                    continue
                
                # Check WhatsApp connection before sending
                if not self.is_whatsapp_connected:
                    self.logger.warning("WhatsApp disconnected - cannot send message")
                    self.api.report_job_result(
                        job['job_id'],
                        'failed',
                        'WhatsApp Web not connected'
                    )
                    consecutive_failures += 1
                    await asyncio.sleep(5)
                    continue
                
                # Process job
                success = await self._process_job(job)
                
                if success:
                    consecutive_failures = 0
                else:
                    consecutive_failures += 1
                
                # Delay between messages
                delay = self.config.get('message_delay_min', 5)
                await asyncio.sleep(delay)
                
            except Exception as e:
                self.logger.error(f"Job processing error: {e}")
                consecutive_failures += 1
                await asyncio.sleep(10)
            
            # Exit if too many consecutive failures
            if consecutive_failures > 10:
                self.logger.critical("Too many consecutive failures - exiting")
                self.is_running = False
    
    async def _process_job(self, job: Dict[str, Any]) -> bool:
        """Process a single job"""
        job_id = job['job_id']
        phone = job['recipient_phone']
        message = job['message_content']
        media_id = job.get('media_file_id')
        
        try:
            self.logger.info(f"Processing job {job_id}: {phone}")
            
            # Download media if present
            media_path = None
            if media_id:
                temp_media_path = f"./temp_media_{media_id}"
                if self.api.download_media(media_id, temp_media_path):
                    media_path = temp_media_path
            
            # Send message
            success = await self.whatsapp.send_message(phone, message, media_path)
            
            # Clean up temp media
            if media_path and os.path.exists(media_path):
                try:
                    os.remove(media_path)
                except:
                    pass
            
            # Report result
            status = 'sent' if success else 'failed'
            error = '' if success else 'Message send failed'
            
            self.api.report_job_result(job_id, status, error)
            
            return success
            
        except Exception as e:
            self.logger.error(f"Job {job_id} processing failed: {e}")
            self.api.report_job_result(job_id, 'failed', str(e))
            return False
    
    async def run(self):
        """Main worker loop"""
        if not await self.initialize():
            return
        
        # Start background tasks
        heartbeat_task = asyncio.create_task(self.send_heartbeat())
        job_task = asyncio.create_task(self.process_jobs())
        
        try:
            # Wait for both tasks (they run until is_running is False)
            await asyncio.gather(heartbeat_task, job_task)
        except KeyboardInterrupt:
            self.logger.info("Received interrupt signal - shutting down...")
        finally:
            await self.shutdown()
    
    async def shutdown(self):
        """Cleanup and shutdown"""
        self.logger.info("Shutting down worker...")
        self.is_running = False
        
        if self.browser:
            await self.browser.close()
        
        self.logger.info("Worker shutdown complete")

# ============================================================================
# MAIN ENTRY POINT
# ============================================================================

async def main():
    """Main entry point"""
    # Setup
    config_file = sys.argv[1] if len(sys.argv) > 1 else 'worker_config.json'
    config = Config(config_file)
    
    logger = WorkerLogger(config.get('log_file', 'worker.log'))
    logger.info("WhatsApp Worker started")
    logger.info(f"Python {sys.version}")
    
    # Run worker
    worker = WhatsAppWorker(config, logger)
    await worker.run()

if __name__ == '__main__':
    asyncio.run(main())
