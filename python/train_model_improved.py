# train_model_improved.py
import os, pandas as pd, joblib
from sklearn.model_selection import train_test_split
from sklearn.feature_extraction.text import CountVectorizer
from sklearn.naive_bayes import MultinomialNB
from sklearn.pipeline import Pipeline
from sklearn.metrics import classification_report

HERE = os.path.dirname(__file__)

def find_dataset():
    candidates = [
        os.path.join(HERE, "..", "dataset_extended.csv"),
        os.path.join(HERE, "dataset_extended.csv"),
        os.path.join(HERE, "..", "dataset.csv"),
        os.path.join(HERE, "dataset.csv"),
    ]
    for p in candidates:
        if os.path.exists(p):
            return os.path.abspath(p)
    raise FileNotFoundError("Không tìm thấy dataset_extended.csv hoặc dataset.csv")

data_path = find_dataset()
print("Using dataset:", data_path)

df = pd.read_csv(data_path).dropna(subset=["text", "label"])
X, y = df["text"].astype(str), df["label"].astype(str)

Xtr, Xte, ytr, yte = train_test_split(X, y, test_size=0.2, random_state=42, stratify=y)

pipe = Pipeline([
    ("vec", CountVectorizer(ngram_range=(1,2), min_df=2)),
    ("clf", MultinomialNB(alpha=0.5)),
])
pipe.fit(Xtr, ytr)

print("\n=== Evaluation ===")
print(classification_report(yte, pipe.predict(Xte)))

art_dir = os.path.join(HERE, "artifacts")
os.makedirs(art_dir, exist_ok=True)
out_path = os.path.join(art_dir, "model_pipeline.pkl")
joblib.dump(pipe, out_path)
print("\nSaved ->", out_path)
