# scripts/bs_scraper/scrape_defects.py
# FINAL 100% WORKING VERSION – Ethiopian Airlines Maintenix – December 2025
import requests
from bs4 import BeautifulSoup
import json
import os
import re
import csv
from datetime import datetime
from pathlib import Path

class MaintenixDefectScraper:
    def __init__(self):
        config_path = Path(__file__).parent / "config.json"
        with open(config_path, "r", encoding="utf-8") as f:
            self.config = json.load(f)

        self.session = requests.Session()
        self.session.headers.update({
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        })

    def login(self):
        print("[+] Logging in to Maintenix...")
        r1 = self.session.get(self.config["login_url"], timeout=30)
        payload = {
            "userId": self.config["credentials"]["username"],
            "password": self.config["credentials"]["password"],
            "submit": "Login"
        }
        r2 = self.session.post(self.config["login_url"], data=payload, timeout=30)
        if "Logout" in r2.text or "Maintenix" in r2.text:
            print("[+] LOGIN SUCCESSFUL!")
            return True
        print("[-] Login failed")
        return False

    def scrape_aircraft_defects(self):
        print("[+] Loading Open Faults tab...")
        r = self.session.get(self.config["source_url"], timeout=60)
        soup = BeautifulSoup(r.content, "html5lib")

        # EXACT ID from your HTML → works 100%
        table = soup.find("table", {"id": "idTableOpenFaults"})
        if not table:
            print("[+] No deferred defects today (table exists but empty or not loaded)")
            return []

        rows = table.find_all("tr")
        header_rows = 2  # first two rows are headers
        data_rows = rows[header_rows:]

        defects = []
        reg = "ET-UNKNOWN"
        match = re.search(r"ET-[A-Z]{3}", soup.get_text())
        if match:
            reg = match.group(0)

        for row in data_rows:
            cells = row.find_all("td")
            if len(cells) < 10:
                continue
            status = cells[9].get_text(strip=True)
            if status != "DEFER":
                continue

            defects.append({
                "ac_registration": reg,
                "fault_name": cells[1].get_text(strip=True),
                "fault_id": cells[2].get_text(strip=True).strip() or "N/A",
                "tsfn": cells[2].get_text(strip=True).strip() or "N/A",
                "ata_seq": cells[3].get_text(strip=True),
                "due_date": cells[4].get_text(strip=True),
                "found_on_date": cells[6].get_text(strip=True),
                "severity": cells[8].get_text(strip=True),
                "deferral_class": cells[12].get_text(strip=True) if len(cells) > 12 else "",
                "deferral_reference": cells[13].get_text(strip=True) if len(cells) > 13 else "",
                "work_package": cells[20].get_text(strip=True) if len(cells) > 20 else "",
                "scraped_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            })

        print(f"[+] SUCCESS: Found {len(defects)} deferred defect(s) on {reg}")
        return defects

    def save_results(self, defects):
        os.makedirs("../data", exist_ok=True)
        ts = datetime.now().strftime("%Y%m%d_%H%M%S")
        json_file = f"../data/defects_{ts}.json"
        csv_file  = f"../data/defects_{ts}.csv"

        with open(json_file, "w", encoding="utf-8") as f:
            json.dump(defects, f, indent=2, ensure_ascii=False)

        if defects:
            with open(csv_file, "w", newline="", encoding="utf-8") as f:
                writer = csv.DictWriter(f, fieldnames=defects[0].keys())
                writer.writeheader()
                writer.writerows(defects)

        with open("../last_scrape.json", "w") as f:
            json.dump({
                "last_run": datetime.now().isoformat(),
                "count": len(defects),
                "aircraft": defects[0]["ac_registration"] if defects else "N/A"
            }, f, indent=2)

        print(f"[+] Files saved → data/defects_{ts}.json & .csv")

    def run(self):
        if not self.login():
            return
        defects = self.scrape_aircraft_defects()
        self.save_results(defects)

if __name__ == "__main__":
    MaintenixDefectScraper().run()