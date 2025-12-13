# scripts/bs_scraper/scrape_defects.py
# FULL FLEET SCRAPER – December 2025 (Updated for new config.json)
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
import sys

class MaintenixDefectScraper:
    def __init__(self):
        # Load config
        config_path = Path(__file__).parent / "config.json"
        if not config_path.exists():
            print(f"[-] ERROR: config.json not found at {config_path}")
            sys.exit(1)

        with open(config_path, "r", encoding="utf-8") as f:
            self.config = json.load(f)

        # Chrome options
        chrome_options = Options()
        chrome_options.add_argument("--headless")
        chrome_options.add_argument("--no-sandbox")
        chrome_options.add_argument("--disable-dev-shm-usage")
        chrome_options.add_argument("--window-size=1920,1080")
        chrome_options.add_argument("--disable-blink-features=AutomationControlled")
        chrome_options.add_experimental_option("excludeSwitches", ["enable-automation"])
        chrome_options.add_experimental_option("useAutomationExtension", False)

        self.driver = webdriver.Chrome(options=chrome_options)
        self.driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => false});")

    def login(self):
        print("[+] Logging in to Maintenix...")
        self.driver.get(self.config["login_url"])
        time.sleep(4)

        try:
            username = self.driver.find_element(By.NAME, "j_username")
            password = self.driver.find_element(By.NAME, "j_password")
            submit = self.driver.find_element(By.XPATH, "//button[@type='submit']")

            username.clear()
            username.send_keys(self.config["credentials"]["username"])
            password.clear()
            password.send_keys(self.config["credentials"]["password"])
            submit.click()

            WebDriverWait(self.driver, 20).until(
                EC.presence_of_element_located((By.LINK_TEXT, "Log Out"))
            )
            print("[+] LOGIN SUCCESSFUL")
            return True
        except Exception as e:
            print(f"[-] LOGIN FAILED: {e}")
            self.driver.save_screenshot("login_failed.png")
            return False

    def scrape_single_aircraft(self, reg, aircraft_data, fleet_name):
        oem_model = aircraft_data.get("oem_model", "UNKNOWN")
        url = aircraft_data["source_url"]

        print(f"[+] Scraping {reg} ({oem_model}) – {fleet_name}")

        # Force Open Faults tab
        if "Open.OpenFaults" not in url:
            url = re.sub(r"aTab=[^&]*", "aTab=Open.OpenFaults", url)
            if "aTab=" not in url:
                url += ("&" if "?" in url else "?") + "aTab=Open.OpenFaults"

        try:
            self.driver.get(url)
            time.sleep(8)

            # Wait for table
            WebDriverWait(self.driver, 25).until(
                EC.presence_of_element_located((By.ID, "idTableOpenFaults"))
            )
        except Exception as e:
            print(f"[-] Table not loaded for {reg}: {e}")
            self.driver.save_screenshot(f"error_{reg}.png")
            return {"registration": reg, "oem_model": oem_model, "defects": [], "error": "Table not loaded"}

        soup = BeautifulSoup(self.driver.page_source, "html5lib")
        table = soup.find("table", {"id": "idTableOpenFaults"})
        if not table:
            print(f"[-] No table found for {reg}")
            return {"registration": reg, "oem_model": oem_model, "defects": [], "error": "No table"}

        rows = table.find_all("tr")[2:]  # Skip header rows
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
                "oem_model": oem_model,
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

        print(f"[+] SUCCESS: {len(defects)} defects from {reg} ({oem_model})")
        return {
            "registration": reg,
            "oem_model": oem_model,
            "fleet": fleet_name,
            "total_defects": len(defects),
            "defects": defects,
            "scraped_at": datetime.now().isoformat()
        }

    def save_aircraft_data(self, result):
        data_dir = Path(__file__).parent.parent / "data"
        data_dir.mkdir(exist_ok=True)

        reg = result["registration"]
        safe_reg = re.sub(r"[^\w\-]", "_", reg)
        filename = data_dir / f"defects_latest_{safe_reg}.json"

        with open(filename, "w", encoding="utf-8") as f:
            json.dump(result, f, indent=2, ensure_ascii=False)

        print(f"    → Saved: {filename.name}")

    def run_full_fleet(self):
        if not self.login():
            return

        all_results = []
        total_aircraft = 0
        total_defects = 0

        print(f"[+] Starting full fleet scrape ({len(self.config['fleets'])} fleets)...\n")

        for fleet_key, fleet in self.config["fleets"].items():
            fleet_name = fleet.get("name", fleet_key)
            aircraft_dict = fleet.get("aircraft", {})

            print(f"\n=== FLEET: {fleet_name} ({len(aircraft_dict)} aircraft) ===")

            for reg, data in aircraft_dict.items():
                total_aircraft += 1
                result = self.scrape_single_aircraft(reg, data, fleet_name)
                self.save_aircraft_data(result)

                all_results.append({
                    "registration": reg,
                    "oem_model": result["oem_model"],
                    "fleet": fleet_name,
                    "defects_count": result["total_defects"],
                    "scraped_at": result["scraped_at"]
                })
                total_defects += result["total_defects"]

        # Global summary
        summary = {
            "last_full_run": datetime.now().isoformat(),
            "total_aircraft_scraped": total_aircraft,
            "total_defects_found": total_defects,
            "aircraft_summary": all_results
        }

        summary_path = Path(__file__).parent.parent / "data" / "last_full_scrape_summary.json"
        with open(summary_path, "w", encoding="utf-8") as f:
            json.dump(summary, f, indent=2)

        print(f"\n[+] FULL FLEET SCRAPE COMPLETE")
        print(f"    Aircraft processed: {total_aircraft}")
        print(f"    Total defects: {total_defects}")
        print(f"    Summary saved: {summary_path.name}")

    def run_single_aircraft(self, registration):
        if not self.login():
            return

        found = False
        for fleet_key, fleet in self.config["fleets"].items():
            if registration in fleet.get("aircraft", {}):
                fleet_name = fleet.get("name", fleet_key)
                data = fleet["aircraft"][registration]
                result = self.scrape_single_aircraft(registration, data, fleet_name)
                self.save_aircraft_data(result)
                print(f"[+] Single aircraft scrape complete: {registration}")
                found = True
                break

        if not found:
            print(f"[-] Aircraft {registration} not found in config.json")

    def close(self):
        self.driver.quit()

if __name__ == "__main__":
    scraper = MaintenixDefectScraper()

    # Default: full fleet
    if len(sys.argv) > 1 and sys.argv[1] == "--single":
        if len(sys.argv) > 2:
            scraper.run_single_aircraft(sys.argv[2])
        else:
            print("Usage: python scrape_defects.py --single ET-ABC")
    else:
        scraper.run_full_fleet()

    scraper.close()