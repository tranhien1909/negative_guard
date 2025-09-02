# predict_app.py
import os
from fastapi import FastAPI
from pydantic import BaseModel
import joblib
import numpy as np

ART_DIR = "artifacts"
MODEL_FILE = os.path.join(ART_DIR, "model_pipeline.pkl")

app = FastAPI(title="Negative Info Guard - Predict API")

class TextIn(BaseModel):
    text: str

# Load pipeline
if not os.path.exists(MODEL_FILE):
    raise RuntimeError(f"Model file not found: {MODEL_FILE}. Run train_model.py first.")
pipe = joblib.load(MODEL_FILE)

# access vectorizer and classifier from pipeline
vec = pipe.named_steps.get("tfidfvectorizer") or pipe.named_steps.get("tfidfvectorizer")
clf = pipe.named_steps.get("logisticregression") or pipe.named_steps.get("logisticregression")

# fallback names if using different pipeline creation
# For safety, try to detect by type
if vec is None:
    for k, v in pipe.named_steps.items():
        from sklearn.feature_extraction.text import TfidfVectorizer as TFIDF
        if isinstance(v, TFIDF):
            vec = v
if clf is None:
    for k, v in pipe.named_steps.items():
        from sklearn.linear_model import LogisticRegression as LR
        if isinstance(v, LR):
            clf = v

@app.post("/predict")
def predict(item: TextIn):
    text = item.text or ""
    X_vec = pipe.named_steps[next(iter(pipe.named_steps))].transform([text]) if False else None

    # Use pipeline directly for label/proba
    label = pipe.predict([text])[0]
    proba_all = pipe.predict_proba([text])[0] if hasattr(pipe, "predict_proba") else None

    # risk_score: probability of 'negative' if present else max prob
    risk_score = 0.0
    if proba_all is not None:
        classes = pipe.named_steps[list(pipe.named_steps.keys())[-1]].classes_ if hasattr(pipe.named_steps[list(pipe.named_steps.keys())[-1]], "classes_") else None
        # better: use classifier classes_
        classes = clf.classes_ if hasattr(clf, "classes_") else None
        if classes is not None and "negative" in classes:
            idx = int(np.where(classes == "negative")[0])
            risk_score = float(proba_all[idx])
        else:
            risk_score = float(np.max(proba_all))

    # explanations: top contributing tokens (simple heuristic)
    explanations = []
    try:
        # get feature names and tfidf vector
        feature_names = vec.get_feature_names_out()
        x = vec.transform([text]).toarray()[0]  # shape (n_features,)
        # find coef for predicted class
        if hasattr(clf, "coef_"):
            classes = clf.classes_
            # pick class index for predicted label
            if label in classes:
                class_idx = int(np.where(classes == label)[0])
            else:
                class_idx = int(np.argmax(clf.coef_.sum(axis=0)))
            coefs = clf.coef_[class_idx]  # shape (n_features,)
            contributions = x * coefs
            # top positive contributions
            top_idx = np.argsort(contributions)[-6:][::-1]
            for i in top_idx:
                if contributions[i] > 0 and x[i] > 0:
                    explanations.append(feature_names[i])
            # limit
            explanations = explanations[:5]
    except Exception:
        explanations = []

    # fallback explanation if empty
    if not explanations:
        explanations = ["(no explanation)"]

    return {
        "label": label,
        "risk_score": round(float(risk_score), 6),
        "explanations": explanations
    }
