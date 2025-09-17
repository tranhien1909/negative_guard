# python/train_model_calibrated.py
import os, joblib, pandas as pd
from sklearn.model_selection import train_test_split
from sklearn.feature_extraction.text import CountVectorizer
from sklearn.naive_bayes import MultinomialNB
from sklearn.calibration import CalibratedClassifierCV
from sklearn.pipeline import Pipeline
from sklearn.metrics import classification_report, confusion_matrix

HERE = os.path.dirname(__file__)
CAL_METHOD = os.getenv("CALIBRATION_METHOD", "sigmoid")  # "sigmoid" ổn định hơn khi data chưa lớn; đổi "isotonic" nếu data nhiều

def find_dataset():
    for p in [
        os.path.join(HERE, "..", "dataset_extended.csv"),
        os.path.join(HERE, "dataset_extended.csv"),
        os.path.join(HERE, "..", "dataset.csv"),
        os.path.join(HERE, "dataset.csv"),
    ]:
        if os.path.exists(p):
            return os.path.abspath(p)
    raise FileNotFoundError("Không thấy dataset_extended.csv / dataset.csv")

def make_calibrated_nb(method="sigmoid", cv=5):
    nb = MultinomialNB(alpha=0.5)
    # scikit-learn mới: dùng "estimator"; scikit-learn cũ: "base_estimator"
    try:
        return CalibratedClassifierCV(estimator=nb, method=method, cv=cv)
    except TypeError:
        return CalibratedClassifierCV(base_estimator=nb, method=method, cv=cv)

data_path = find_dataset()
print("Using dataset:", data_path)

df = pd.read_csv(data_path).dropna(subset=["text","label"])
X, y = df["text"].astype(str), df["label"].astype(str)

Xtr, Xte, ytr, yte = train_test_split(X, y, test_size=0.2, random_state=42, stratify=y)

pipe = Pipeline([
    ("vec", CountVectorizer(ngram_range=(1,2), min_df=2)),
    ("cal", make_calibrated_nb(method=CAL_METHOD, cv=5)),
])

pipe.fit(Xtr, ytr)
pred = pipe.predict(Xte)

print("\n=== Report ===")
print(classification_report(yte, pred))
print(confusion_matrix(yte, pred))

art_dir = os.path.join(HERE, "artifacts")
os.makedirs(art_dir, exist_ok=True)
outp = os.path.join(art_dir, "model_pipeline.pkl")
joblib.dump(pipe, outp)
print("\nSaved ->", outp)
