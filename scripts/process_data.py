# process_data.py
from data_processor import DefectDataProcessor

# One line = everything
processor = DefectDataProcessor()
df = processor.process_latest()

# Now you have a perfect, clean DataFrame
print(df[['aircraft_reg', 'description', 'status', 'due_date']].head(10))