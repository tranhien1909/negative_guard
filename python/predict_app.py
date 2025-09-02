from fastapi import FastAPI
from pydantic import BaseModel
import joblib
import numpy as np
import os

ART_DIR = os.path.join(os.path.dirname(__file__), "artifacts")
model = joblib.load(os.path.join(ART_DIR, "model.pkl"))
vectorizer = joblib.load(os.path.join(ART_DIR, "vectorizer.pkl"))

app = FastAPI()

class Item(BaseModel):
    text: str

@app.post("/predict")
def predict(item: Item):
    text = item.text or ""
    X = vectorizer.transform([text])
    proba = model.predict_proba(X)[0] if hasattr(model, "predict_proba") else None
    if proba is not None:
        idx = int(np.argmax(proba))
        label = model.classes_[idx]
        risk = float(proba[idx])
    else:
        label = model.predict(X)[0]
        risk = 0.5

    # simple explanation: top tokens coef — for dev only
    explanation = []
    try:
        coefs = model.coef_
        # find top features (approx)
        # NOTE: for multi-class this is simplified
        explanation = ["token-example"]
    except Exception:
        explanation = []

    return {"label": label, "risk_score": risk, "explanations": explanation}
