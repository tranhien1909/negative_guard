# đơn giản để dev có model.pkl + vectorizer.pkl
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.pipeline import make_pipeline
import joblib

# dataset mẫu (rất nhỏ, dev phải thay bằng dữ liệu thật)
X = [
    "Trúng thưởng, nhấn vào link để nhận quà 1000000đ",
    "Hôm nay trường thông báo thay đổi lịch thi",
    "Lừa đảo, chuyển tiền vào tài khoản này",
    "Học bổng dành cho sinh viên IUH"
]
y = ["scam", "info", "scam", "info"]

vec = TfidfVectorizer(ngram_range=(1,2), max_features=2000)
Xv = vec.fit_transform(X)
clf = LogisticRegression()
clf.fit(Xv, y)

joblib.dump(clf, "artifacts/model.pkl")
joblib.dump(vec, "artifacts/vectorizer.pkl")
print("Saved model and vectorizer to artifacts/")
