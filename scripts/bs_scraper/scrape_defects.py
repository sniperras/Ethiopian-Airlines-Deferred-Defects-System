# scripts/bs_scraper/scrape_defects.py
# ULTIMATE FINAL + OEM MODEL (737/787/777) – December 2025
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from bs4 import BeautifulSoup
import json
import time
from datetime import datetime
from pathlib import Path
import re

class MaintenixDefectScraper:
    def __init__(self):
        config_path = Path(__file__).parent / "config.json"
        with open(config_path, "r", encoding="utf-8") as f:
            self.config = json.load(f)

        chrome_options = Options()
        chrome_options.add_argument("--headless")  # Remove to see browser
        chrome_options.add_argument("--no-sandbox")
        chrome_options.add_argument("--disable-dev-shm-usage")
        chrome_options.add_argument("--window-size=1920,1080")
        self.driver = webdriver.Chrome(options=chrome_options)

    def login(self):
        print("[+] Logging in...")
        self.driver.get(self.config["login_url"])
        time.sleep(3)
        self.driver.find_element(By.NAME, "j_username").send_keys(self.config["credentials"]["username"])
        self.driver.find_element(By.NAME, "j_password").send_keys(self.config["credentials"]["password"])
        self.driver.find_element(By.XPATH, "//button[@type='submit']").click()

        try:
            WebDriverWait(self.driver, 15).until(EC.presence_of_element_located((By.LINK_TEXT, "Log Out")))
            print("[+] LOGIN SUCCESSFUL!")
            return True
        except:
            print("[-] Login failed")
            self.driver.save_screenshot("login_failed.png")
            return False

    def scrape_aircraft_defects(self):
        print("[+] Loading Open.OpenFaults tab...")
        url = self.config["source_url"]
        if "Open.OpenFaults" not in url:
            url = re.sub(r"aTab=[^&]*", "aTab=Open.OpenFaults", url) if "aTab=" in url else url + "&aTab=Open.OpenFaults"
        self.driver.get(url)
        time.sleep(10)

        try:
            WebDriverWait(self.driver, 20).until(EC.presence_of_element_located((By.ID, "idTableOpenFaults")))
        except:
            print("[-] Table not loaded")
            self.driver.save_screenshot("table_error.png")
            return []

        soup = BeautifulSoup(self.driver.page_source, "html5lib")
        table = soup.find("table", {"id": "idTableOpenFaults"})
        rows = table.find_all("tr")[2:]

        # Extract OEM Part Number (e.g. 737-860 → 737)
        oem_model = "UNKNOWN"
        oem_link = soup.find("td", {"id": "idCellOemPartNumberLabel"})
        if oem_link:
            link = oem_link.find_next("a")
            if link:
                full = link.get_text(strip=True)
                # Extract first 3 digits (737, 777, 787, 767, etc.)
                match = re.match(r"(\d{3})", full)
                oem_model = match.group(1) if match else "UNKNOWN"

        reg = "ET-UNKNOWN"
        match = re.search(r"ET-[A-Z]{3}", self.driver.page_source)
        if match:
            reg = match.group(0)

        print(f"[+] Aircraft: {reg} | OEM Model: {oem_model} | Parsing {len(rows)} rows...")

        defects = []
        for row in rows:
            cells = row.find_all("td")
            if len(cells) < 20:
                continue

            def txt(i, default=""):
                return cells[i].get_text(strip=True) if i < len(cells) else default

            status = txt(9).upper()
            if status not in ["DEFER", "OPEN", "NFF"]:
                continue

            defects.append({
                "ac_registration": reg,
                "oem_model": oem_model,                    # NEW: 737, 787, etc.
                "fault_name": txt(1),
                "fault_id": txt(2),
                "tsfn": txt(2),
                "config_position": txt(3),
                "due_date": txt(4),
                "inventory": txt(5),
                "found_on_date": txt(6),
                "found_on_flight": txt(7),
                "severity": txt(8),
                "status": status,
                "failure_type": txt(10),
                "fault_priority": txt(11),
                "deferral_class": txt(12),
                "deferral_reference": txt(13),
                "work_types": txt(14),
                "driving_task_name": txt(15),
                "driving_task_id": txt(16),
                "operational_restrictions": txt(17),
                "etops_significant": txt(18),
                "work_package_name": txt(19),
                "work_package_id": txt(20),
                "work_package_no": txt(21),
                "ro": txt(22),
                "material_availability": txt(23),
                "scraped_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            })

        print(f"[+] SUCCESS: Extracted {len(defects)} defects from {reg} ({oem_model})")
        return defects

    def save_results(self, defects):
        data_dir = Path(__file__).parent.parent / "data"
        data_dir.mkdir(exist_ok=True)
        
        ts = datetime.now().strftime("%Y%m%d_%H%M%S")
        latest = data_dir / "defects_latest.json"
        timestamped = data_dir / f"defects_{ts}.json"
        
        data = {
            "scraped_at": datetime.now().isoformat(),
            "aircraft": defects[0]["ac_registration"] if defects else "N/A",
            "oem_model": defects[0]["oem_model"] if defects else "UNKNOWN",
            "total_defects": len(defects),
            "defects": defects
        }
        
        with open(latest, "w", encoding="utf-8") as f:
            json.dump(data, f, indent=2, ensure_ascii=False)
        with open(timestamped, "w", encoding="utf-8") as f:
            json.dump(data, f, indent=2, ensure_ascii=False)

        with open("last_scrape.json", "w") as f:
            json.dump({
                "last_run": datetime.now().isoformat(),
                "count": len(defects),
                "oem_model": data["oem_model"]
            }, f, indent=2)

        print(f"[+] FULL DATA + OEM MODEL SAVED → defects_latest.json")

    def run(self):
        try:
            if not self.login():
                return
            defects = self.scrape_aircraft_defects()
            self.save_results(defects)
        finally:
            self.driver.quit()

if __name__ == "__main__":
    MaintenixDefectScraper().run()