import os
from typing import List
import logging
import numpy as np
from datetime import datetime

from fastapi import FastAPI, Depends, Request, HTTPException, status, Header
from pydantic import BaseModel
from sentence_transformers import SentenceTransformer

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

class TextIn(BaseModel):
    text: List[str]

API_TOKEN = os.getenv("API_TOKEN")
SEARCH_EMBEDDING_MODEL = os.getenv("SEARCH_EMBEDDING_MODEL")

if not API_TOKEN:
    raise ValueError("Need to set tup API_TOKEN")

model = SentenceTransformer(SEARCH_EMBEDDING_MODEL)

app = FastAPI(
    title="Text Vectorization API",
    description="API for embedding.",
    version="1.0.0",
)

def verify_token(request: Request):
    token = request.headers.get("Authorization")
    if token != f"Bearer {API_TOKEN}":
        raise HTTPException(status_code=401, detail="Unauthorized")


@app.get("/", tags=["Root"])
async def read_root():
    return {"status": "ok", "message": "Work."}

@app.get("/vectorize", tags=["Vectorization"])
async def vectorize_health_check():
    return {"status": "ok", "message": "Ready to receive POST requests for vectorization."}

@app.post(
    "/vectorize",
    dependencies=[Depends(verify_token)],
)
async def vectorize(data: TextIn):
    try:
        vec = model.encode(data.text, convert_to_numpy=True)
        vec_norm = vec / np.linalg.norm(vec)

        return vec_norm.tolist()
    except Exception as e:
        logger.error(f"Error during vectorization: {e}")
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Request error: {e}",
        )
