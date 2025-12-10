import requests
from bs4 import BeautifulSoup
import pandas as pd
from datetime import datetime
import logging
from typing import List, Dict, Optional
import time

class DefectScraper:
    """
    Web scraper for extracting deferred defect data from airline maintenance systems
    """
    
    def __init__(self, base_url: str, session_timeout: int = 30):
        self.base_url = base_url
        self.session = requests.Session()
        self.session_timeout = session_timeout
        self.session.headers.update({
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        })
        self.logger = self._setup_logger()
        
    def _setup_logger(self):
        """Configure logging for scraping activities"""
        logging.basicConfig(
            level=logging.INFO,
            format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
            handlers=[
                logging.FileHandler('scraping.log'),
                logging.StreamHandler()
            ]
        )
        return logging.getLogger(__name__)
    
    def fetch_page(self, url: str, params: Dict = None) -> Optional[BeautifulSoup]:
        """
        Fetch and parse HTML page using BeautifulSoup
        """
        try:
            response = self.session.get(url, timeout=self.session_timeout, params=params)
            response.raise_for_status()
            
            soup = BeautifulSoup(response.content, 'html5lib')
            self.logger.info(f"Successfully fetched {url}")
            return soup
            
        except requests.RequestException as e:
            self.logger.error(f"Failed to fetch {url}: {e}")
            return None
    
    def extract_defect_table(self, soup: BeautifulSoup) -> List[Dict]:
        """
        Extract defect data from HTML tables
        """
        defects = []
        
        # Find all tables that might contain defect data
        tables = soup.find_all('table', class_=['defect-table', 'data-table', 'maintenance-table'])
        
        if not tables:
            # Try to find any table
            tables = soup.find_all('table')
            self.logger.info(f"Found {len(tables)} generic tables")
        
        for table in tables:
            defects.extend(self._parse_table(table))
            
        return defects
    
    def _parse_table(self, table: BeautifulSoup) -> List[Dict]:
        """
        Parse individual table for defect information
        """
        rows = table.find_all('tr')
        if len(rows) < 2:  # Need header + at least 1 data row
            return []
        
        # Extract headers
        header_row = rows[0]
        headers = [th.get_text(strip=True) for th in header_row.find_all(['th', 'td'])]
        
        # Standardize headers for Ethiopian Airlines system
        headers = self._standardize_headers(headers)
        
        defects = []
        for row in rows[1:]:
            cells = row.find_all(['td', 'th'])
            if len(cells) == len(headers):
                defect_data = dict(zip(headers, [cell.get_text(strip=True) for cell in cells]))
                defects.append(self._clean_defect_data(defect_data))
        
        return defects
    
    def _standardize_headers(self, headers: List[str]) -> List[str]:
        """
        Map common airline system headers to standard format
        """
        header_mapping = {
            'defect_id': ['defect_id', 'id', 'defect #', 'defect_no'],
            'aircraft_reg': ['reg', 'registration', 'aircraft', 'tail_number'],
            'ata_code': ['ata', 'ata_code', 'system', 'component'],
            'description': ['description', 'defect_description', 'issue', 'problem'],
            'status': ['status', 'current_status', 'state'],
            'deferred_date': ['date', 'deferred_date', 'reported_date'],
            'technician': ['technician', 'assigned_to', 'responsible'],
            'priority': ['priority', 'severity', 'urgency']
        }
        
        standardized = []
        used_mapping = set()
        
        for header in headers:
            header_lower = header.lower().strip()
            mapped = False
            
            for standard_key, variants in header_mapping.items():
                if header_lower in [v.lower() for v in variants] and standard_key not in used_mapping:
                    standardized.append(standard_key)
                    used_mapping.add(standard_key)
                    mapped = True
                    break
            
            if not mapped:
                standardized.append(f'field_{len(standardized)}')
        
        return standardized
    
    def _clean_defect_data(self, data: Dict) -> Dict:
        """
        Clean and validate scraped defect data
        """
        cleaned = {}
        
        # Clean common fields
        if 'defect_id' in data:
            cleaned['defect_id'] = data['defect_id'].strip()
        
        if 'aircraft_reg' in data:
            cleaned['aircraft_reg'] = data['aircraft_reg'].strip().upper()
        
        if 'ata_code' in data:
            cleaned['ata_code'] = data['ata_code'].strip()
        
        if 'description' in data:
            cleaned['description'] = data['description'].strip()
            # Truncate long descriptions
            if len(cleaned['description']) > 500:
                cleaned['description'] = cleaned['description'][:500] + '...'
        
        if 'status' in data:
            cleaned['status'] = data['status'].strip().title()
        
        if 'deferred_date' in data:
            cleaned['deferred_date'] = self._parse_date(data['deferred_date'])
        
        if 'priority' in data:
            cleaned['priority'] = self._normalize_priority(data['priority'])
        
        # Add metadata
        cleaned.update({
            'scraped_at': datetime.now().isoformat(),
            'source': 'ethiopian_airlines_system'
        })
        
        return cleaned
    
    def _parse_date(self, date_str: str) -> str:
        """
        Parse various date formats used in airline systems
        """
        date_formats = [
            '%Y-%m-%d',
            '%m/%d/%Y',
            '%d/%m/%Y',
            '%Y-%m-%d %H:%M:%S',
            '%d-%b-%Y'
        ]
        
        for fmt in date_formats:
            try:
                parsed = datetime.strptime(date_str.strip(), fmt)
                return parsed.strftime('%Y-%m-%d')
            except ValueError:
                continue
        
        return date_str.strip()  # Return original if parsing fails
    
    def _normalize_priority(self, priority: str) -> str:
        """
        Normalize priority levels
        """
        priority_map = {
            'critical': 'CRITICAL',
            'high': 'HIGH',
            'medium': 'MEDIUM',
            'low': 'LOW',
            '1': 'CRITICAL',
            '2': 'HIGH',
            '3': 'MEDIUM',
            '4': 'LOW'
        }
        
        key = priority.lower().strip()
        return priority_map.get(key, 'MEDIUM')
    
    def scrape_defects(self, url: str = None, date_range: tuple = None) -> pd.DataFrame:
        """
        Main scraping method
        """
        if url is None:
            url = self.base_url
        
        self.logger.info(f"Starting scrape from {url}")
        
        soup = self.fetch_page(url)
        if not soup:
            return pd.DataFrame()
        
        defects = self.extract_defect_table(soup)
        
        if not defects:
            self.logger.warning("No defects found in the scraped page")
            return pd.DataFrame()
        
        # Convert to DataFrame
        df = pd.DataFrame(defects)
        
        # Filter by date range if provided
        if date_range:
            start_date, end_date = date_range
            df = df[(df['deferred_date'] >= start_date) & 
                   (df['deferred_date'] <= end_date)]
        
        self.logger.info(f"Scraped {len(df)} defects")
        return df
    
    def scrape_with_pagination(self, base_url: str, max_pages: int = 10) -> pd.DataFrame:
        """
        Handle paginated results
        """
        all_defects = []
        
        for page in range(1, max_pages + 1):
            page_url = f"{base_url}?page={page}"
            self.logger.info(f"Scraping page {page}")
            
            soup = self.fetch_page(page_url)
            if not soup:
                break
            
            page_defects = self.extract_defect_table(soup)
            if not page_defects:
                self.logger.info(f"No more data on page {page}")
                break
            
            all_defects.extend(page_defects)
            time.sleep(1)  # Be respectful to the server
        
        return pd.DataFrame(all_defects)