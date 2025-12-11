# data_processor.py - FINAL VERSION (works with your current scraper)
import pandas as pd
from typing import Dict, List, Any
import json
from pathlib import Path
from datetime import datetime

class DefectDataProcessor:
    """
    Process and validate Maintenix scraped defect data (Dec 2025 version)
    """
    
    def __init__(self, data_dir: str = "data"):
        self.data_dir = Path(data_dir)
        self.data_dir.mkdir(exist_ok=True)
    
    def load_latest(self) -> pd.DataFrame:
        """Load defects_latest.json from scraper"""
        latest_file = self.data_dir / "defects_latest.json"
        if not latest_file.exists():
            print("No defects_latest.json found. Run scraper first.")
            return pd.DataFrame()
        
        with open(latest_file, "r", encoding="utf-8") as f:
            data = json.load(f)
        
        df = pd.DataFrame(data)
        print(f"Loaded {len(df)} defects from {latest_file.name}")
        return df
    
    def standardize_columns(self, df: pd.DataFrame) -> pd.DataFrame:
        """Map scraper output → standard column names"""
        if df.empty:
            return df
        
        # Rename columns to match your database/app
        rename_map = {
            "ac_registration": "aircraft_reg",
            "fault_name": "description",
            "fault_id": "defect_id",
            "tsfn": "tsfn",
            "ata_seq": "ata",
            "due_date": "due_date",
            "status": "status",
            "severity": "severity",
            "deferral_class": "deferral_class",
            "scraped_at": "scraped_at"
        }
        
        df = df.rename(columns=rename_map)
        
        # Ensure required columns exist
        for col in ['defect_id', 'aircraft_reg', 'description', 'status']:
            if col not in df.columns:
                df[col] = "N/A"
        
        return df
    
    def validate_and_clean(self, df: pd.DataFrame) -> pd.DataFrame:
        """Full validation and cleaning"""
        if df.empty:
            return df
        
        print(f"Starting with {len(df)} defects")
        
        # Standardize column names
        df = self.standardize_columns(df)
        
        # Remove exact duplicates
        initial = len(df)
        df = df.drop_duplicates(subset=['tsfn', 'description'], keep='last')
        print(f"Removed {initial - len(df)} duplicates")
        
        # Clean status
        df['status'] = df['status'].str.upper().str.strip()
        
        # Parse dates
        if 'due_date' in df.columns:
            df['due_date'] = pd.to_datetime(df['due_date'], errors='coerce', dayfirst=False)
        
        return df
    
    def save_clean_data(self, df: pd.DataFrame, prefix: str = "clean_defects") -> Dict[str, str]:
        """Save cleaned data to CSV + JSON + latest"""
        if df.empty:
            print("No data to save")
            return {}
        
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        
        files = {
            "json": self.data_dir / f"{prefix}_{timestamp}.json",
            "csv": self.data_dir / f"{prefix}_{timestamp}.csv",
            "latest_json": self.data_dir / f"{prefix}_latest.json",
            "latest_csv": self.data_dir / f"{prefix}_latest.csv"
        }
        
        # Save timestamped
        df.to_json(files["json"], orient="records", indent=2, date_format="iso")
        df.to_csv(files["csv"], index=False)
        
        # Save latest
        df.to_json(files["latest_json"], orient="records", indent=2, date_format="iso")
        df.to_csv(files["latest_csv"], index=False)
        
        print(f"SAVED CLEAN DATA:")
        print(f"   • {len(df)} defects")
        print(f"   • JSON: {files['latest_json'].name}")
        print(f"   • CSV:  {files['latest_csv'].name}")
        
        return {k: str(v) for k, v in files.items()}
    
    def generate_summary(self, df: pd.DataFrame) -> Dict[str, Any]:
        """Generate beautiful summary"""
        if df.empty:
            return {"message": "No defects found"}
        
        return {
            "total_defects": len(df),
            "unique_aircraft": df['aircraft_reg'].nunique(),
            "by_status": df['status'].value_counts().to_dict(),
            "by_severity": df['severity'].value_counts().to_dict() if 'severity' in df.columns else {},
            "by_aircraft": df['aircraft_reg'].value_counts().head(10).to_dict(),
            "due_this_month": len(df[df['due_date'].dt.month == datetime.now().month]) if 'due_date' in df.columns and pd.notna(df['due_date']).any() else 0,
            "scraped_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        }
    
    def process_latest(self) -> pd.DataFrame:
        """One-click: load → clean → save → return DataFrame"""
        df = self.load_latest()
        if df.empty:
            return df
        
        df = self.validate_and_clean(df)
        self.save_clean_data(df)
        
        summary = self.generate_summary(df)
        print("\nSUMMARY:")
        for k, v in summary.items():
            print(f"   • {k}: {v}")
        
        return df

# BONUS: Run with one line
if __name__ == "__main__":
    processor = DefectDataProcessor()
    df = processor.process_latest()