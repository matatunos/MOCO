FROM python:3.11-slim

WORKDIR /app

# Install dependencies
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy application
COPY backend/ ./backend/
COPY frontend/ ./frontend/
COPY uploads/ ./uploads/

# Create .env file
ENV FLASK_APP=backend/app.py
ENV PYTHONPATH=/app

EXPOSE 5000

# Run the application
CMD ["python", "backend/app.py"]
