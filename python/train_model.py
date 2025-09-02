import os
import pandas as pd
from sklearn.feature_extraction.text import CountVectorizer
from sklearn.naive_bayes import MultinomialNB
from sklearn.pipeline import make_pipeline
import joblib

# Load dataset
df = pd.read_csv("dataset.csv")
X = df["text"]
y = df["label"]

# Train pipeline (vectorizer + model)
model_pipeline = make_pipeline(CountVectorizer(), MultinomialNB())
model_pipeline.fit(X, y)

# Tạo thư mục artifacts nếu chưa có
os.makedirs("artifacts", exist_ok=True)

# Lưu pipeline gộp chung
joblib.dump(model_pipeline, "artifacts/model_pipeline.pkl")

print("✅ Saved pipeline to artifacts/model_pipeline.pkl")

