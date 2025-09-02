# create_dataset.py
import csv
import os

rows = [
# negative (nói xấu / xúc phạm)
("Giáo viên ở trường IUH dạy rất tệ, chẳng quan tâm đến sinh viên", "negative"),
("Đcm ông thầy này dạy như hạch, bắt sinh viên đi tiền mới được điểm", "negative"),
("Con mẹ nó, học cái môn này chỉ tốn tiền, thầy chả biết gì", "negative"),
("Ông thầy chỉ giỏi chửi mắng, chứ kiến thức thì bằng không", "negative"),
("Lớp học ồn ào mà giảng viên không thèm quản lý", "negative"),
("Giáo viên đi trễ liên tục, dạy cho có lệ", "negative"),
("Đề thi toàn đánh đố, học bao nhiêu cũng rớt", "negative"),
("Thầy cô không công bằng, thiên vị sinh viên", "negative"),
("Giáo viên hay xúc phạm sinh viên trước lớp", "negative"),
("Học phí quá cao so với chất lượng đào tạo", "negative"),

# neutral (thông tin trung lập)
("Trường IUH nằm ở Gò Vấp, có nhiều ngành đào tạo khác nhau", "neutral"),
("Học kỳ này tôi đăng ký 6 môn", "neutral"),
("Hôm qua trường tổ chức hội thảo khoa học", "neutral"),
("Thời khóa biểu mới vừa được cập nhật", "neutral"),
("Thư viện trường mở cửa từ 7h sáng", "neutral"),
("Khoa CNTT có khoảng 2000 sinh viên", "neutral"),
("Trường có ký túc xá cho sinh viên ở xa", "neutral"),
("Ngày mai có buổi thi cuối kỳ", "neutral"),
("Phòng học trang bị máy chiếu, quạt, đèn đầy đủ", "neutral"),
("Giảng viên yêu cầu nộp báo cáo nhóm tuần tới", "neutral"),

# positive (khen ngợi / tích cực)
("Thầy dạy rất nhiệt tình, dễ hiểu", "positive"),
("Giáo viên IUH quan tâm và hỗ trợ sinh viên tốt", "positive"),
("Giảng viên giảng bài rõ ràng, chi tiết", "positive"),
("Trường IUH cơ sở vật chất khang trang", "positive"),
("Thư viện đầy đủ sách tham khảo, rất tiện", "positive"),
("Thầy cô thân thiện, luôn giúp đỡ sinh viên", "positive"),
("Chương trình học cập nhật hiện đại", "positive"),
("Hoạt động ngoại khóa sôi nổi, bổ ích", "positive"),
("Học phí hợp lý so với chất lượng", "positive"),
("Trường tạo nhiều cơ hội việc làm cho sinh viên", "positive"),
]

os.makedirs("artifacts", exist_ok=True)
with open("dataset.csv", "w", encoding="utf-8", newline="") as f:
    writer = csv.writer(f)
    writer.writerow(["text", "label"])
    for t, l in rows:
        writer.writerow([t, l])

print("Saved dataset.csv with", len(rows), "rows")
