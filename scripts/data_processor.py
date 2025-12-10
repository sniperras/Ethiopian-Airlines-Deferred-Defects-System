import pandas as pd
from typing import Dict, List
import json
from pathlib import Path

class DefectDataProcessor:
    """
    Process and validate scraped defect data
    """
    
    def __init__(self, output_dir: str = "data"):
        self.output_dir = Path(output_dir)
        self.output_dir.mkdir(exist_ok=True)
    
    def validate_defects(self, df: pd.DataFrame) -> pd.DataFrame:
        """
        Validate scraped defect data
        """
        if df.empty:
            return df
        
        # Required fields validation
        required_fields = ['defect_id', 'aircraft_reg', 'description', 'status']
        missing_fields = [field for field in required_fields if field not in df.columns]
        
        if missing_fields:
            print(f"Warning: Missing required fields: {missing_fields}")
        
        # Remove duplicates based on defect_id
        initial_count = len(df)
        df = df.drop_duplicates(subset=['defect_id'], keep='last')
        print(f"Removed {initial_count - len(df)} duplicate defects")
        
        # Clean data types
        if 'deferred_date' in df.columns:
            df['deferred_date'] = pd.to_datetime(df['deferred_date'], errors='coerce')
        
        return df
    
    def save_to_csv(self, df: pd.DataFrame, filename: str = None) -> str:
        """
        Save defects to CSV
        """
        if filename is None:
            timestamp = pd.Timestamp.now().strftime('%Y%m%d_%H%M%S')
            filename = f"defects_{timestamp}.csv"
        
        filepath = self.output_dir / filename
        df.to_csv(filepath, index=False)
        print(f"Saved {len(df)} defects to {filepath}")
        return str(filepath)
    
    def save_to_json(self, df: pd.DataFrame, filename: str = None) -> str:
        """
        Save defects to JSON
        """
        if filename is None:
            timestamp = pd.Timestamp.now().strftime('%Y%m%d_%H%M%S')
            filename = f"defects_{timestamp}.json"
        
        filepath = self.output_dir / filename
        
        # Convert DataFrame to list of dictionaries for JSON
        defects_json = df.to_dict('records')
        with open(filepath, 'w') as f:
            json.dump(defects_json, f, indent=2, default=str)
        
        print(f"Saved {len(df)} defects to {filepath}")
        return str(filepath)
    
    def generate_summary(self, df: pd.DataFrame) -> Dict:
        """
        Generate summary statistics
        """
        if df.empty:
            return {}
        
        summary = {
            'total_defects': len(df),
            'unique_aircraft': df['aircraft_reg'].nunique() if 'aircraft_reg' in df else 0,
            'status_distribution': df['status'].value_counts().to_dict() if 'status' in df else {},
            'priority_distribution': df['priority'].value_counts().to_dict() if 'priority' in df else {},
            'date_range': {
                'earliest': df['deferred_date'].min().strftime('%Y-%m-%d') if 'deferred_date' in df and not df['deferred_date'].isna().all() else None,
                'latest': df['deferred_date'].max().strftime('%Y-%m-%d') if 'deferred_date' in df and not df['deferred_date'].isna().all() else None
            }
        }
        
        return summary