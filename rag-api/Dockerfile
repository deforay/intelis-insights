FROM python:3.11-slim

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app
EXPOSE 8089
ENV QDRANT_URL=http://qdrant:6333
ENV QDRANT_COLLECTION=intelis_rag
ENV EMBEDDING_MODEL=BAAI/bge-small-en-v1.5

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8089"]