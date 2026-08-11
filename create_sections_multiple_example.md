# Ví dụ sử dụng service create_sections_multiple

## Service: local_custom_service_create_sections_multiple

### Mô tả
Service này cho phép tạo nhiều section trong một lần gọi với đầy đủ thông tin như tên, mô tả, vị trí, visibility, etc.

## Cấu trúc Parameters

```json
{
  "courseid": 123,
  "sections": [
    {
      "position": 0,
      "name": "Section 1",
      "summary": "Mô tả section 1",
      "summaryformat": 1,
      "visible": 1,
      "availability": ""
    },
    {
      "position": 0,
      "name": "Section 2",
      "summary": "Mô tả section 2",
      "summaryformat": 1,
      "visible": 1
    }
  ]
}
```

### Các field trong sections:
- **position** (int, optional, default: 0): Vị trí chèn section (0 = cuối khóa học)
- **name** (string, optional): Tên section
- **summary** (string, optional): Mô tả section
- **summaryformat** (int, optional, default: 1): Format của summary (0=MOODLE, 1=HTML, 2=PLAIN, 4=MARKDOWN)
- **visible** (int, optional, default: 1): Hiển thị section (0=ẩn, 1=hiện)
- **availability** (string, optional): Điều kiện availability dạng JSON

## Ví dụ cURL

### 1. Tạo 2 sections đơn giản

```bash
curl -X POST "https://your-moodle-site.com/webservice/rest/server.php" \
  -d "wstoken=YOUR_TOKEN" \
  -d "wsfunction=local_custom_service_create_sections_multiple" \
  -d "moodlewsrestformat=json" \
  -d "courseid=123" \
  -d "sections[0][position]=0" \
  -d "sections[0][name]=Section 1" \
  -d "sections[0][summary]=Mô tả section 1" \
  -d "sections[0][summaryformat]=1" \
  -d "sections[0][visible]=1" \
  -d "sections[1][position]=0" \
  -d "sections[1][name]=Section 2" \
  -d "sections[1][summary]=Mô tả section 2" \
  -d "sections[1][summaryformat]=1" \
  -d "sections[1][visible]=1"
```

### 2. Tạo sections với JSON (REST format)

```bash
curl -X POST "https://your-moodle-site.com/webservice/rest/server.php" \
  -H "Content-Type: application/json" \
  -d '{
    "wstoken": "YOUR_TOKEN",
    "wsfunction": "local_custom_service_create_sections_multiple",
    "moodlewsrestformat": "json",
    "courseid": 123,
    "sections": [
      {
        "position": 0,
        "name": "Chương 1: Giới thiệu",
        "summary": "<p>Đây là chương đầu tiên của khóa học</p>",
        "summaryformat": 1,
        "visible": 1
      },
      {
        "position": 0,
        "name": "Chương 2: Nội dung chính",
        "summary": "<p>Chương này sẽ đi sâu vào nội dung chính</p>",
        "summaryformat": 1,
        "visible": 1
      },
      {
        "position": 0,
        "name": "Chương 3: Kết luận",
        "summary": "<p>Tổng kết và kết luận</p>",
        "summaryformat": 1,
        "visible": 1
      }
    ]
  }'
```

### 3. Tạo sections với form-data (multipart)

```bash
curl -X POST "https://your-moodle-site.com/webservice/rest/server.php" \
  -F "wstoken=YOUR_TOKEN" \
  -F "wsfunction=local_custom_service_create_sections_multiple" \
  -F "moodlewsrestformat=json" \
  -F "courseid=123" \
  -F "sections[0][position]=0" \
  -F "sections[0][name]=Section 1" \
  -F "sections[0][summary]=Mô tả section 1" \
  -F "sections[0][summaryformat]=1" \
  -F "sections[0][visible]=1" \
  -F "sections[1][position]=0" \
  -F "sections[1][name]=Section 2" \
  -F "sections[1][summary]=Mô tả section 2" \
  -F "sections[1][summaryformat]=1" \
  -F "sections[1][visible]=1"
```

### 4. Tạo sections với PHP

```php
<?php
require_once('config.php');
require_once($CFG->libdir . '/externallib.php');

$token = 'YOUR_TOKEN';
$domainname = 'https://your-moodle-site.com';
$functionname = 'local_custom_service_create_sections_multiple';

$restformat = 'json'; // Also possible: 'xml'

$params = array(
    'courseid' => 123,
    'sections' => array(
        array(
            'position' => 0,
            'name' => 'Section 1',
            'summary' => 'Mô tả section 1',
            'summaryformat' => 1,
            'visible' => 1
        ),
        array(
            'position' => 0,
            'name' => 'Section 2',
            'summary' => 'Mô tả section 2',
            'summaryformat' => 1,
            'visible' => 1
        )
    )
);

$serverurl = $domainname . '/webservice/rest/server.php' . '?wstoken=' . $token . '&wsfunction=' . $functionname . '&moodlewsrestformat=' . $restformat;

$curl = new curl();
$resp = $curl->post($serverurl, $params);

$response = json_decode($resp, true);

print_r($response);
?>
```

### 5. Tạo sections với JavaScript (fetch)

```javascript
const token = 'YOUR_TOKEN';
const domainname = 'https://your-moodle-site.com';
const functionname = 'local_custom_service_create_sections_multiple';

const params = {
    wstoken: token,
    wsfunction: functionname,
    moodlewsrestformat: 'json',
    courseid: 123,
    sections: [
        {
            position: 0,
            name: 'Section 1',
            summary: 'Mô tả section 1',
            summaryformat: 1,
            visible: 1
        },
        {
            position: 0,
            name: 'Section 2',
            summary: 'Mô tả section 2',
            summaryformat: 1,
            visible: 1
        }
    ]
};

// Convert to form data
const formData = new URLSearchParams();
Object.keys(params).forEach(key => {
    if (key === 'sections') {
        params[key].forEach((section, index) => {
            Object.keys(section).forEach(sectionKey => {
                formData.append(`sections[${index}][${sectionKey}]`, section[sectionKey]);
            });
        });
    } else {
        formData.append(key, params[key]);
    }
});

fetch(`${domainname}/webservice/rest/server.php`, {
    method: 'POST',
    body: formData
})
.then(response => response.json())
.then(data => console.log(data))
.catch(error => console.error('Error:', error));
```

## Response Format

### Success Response

```json
{
  "sections": [
    {
      "sectionid": 456,
      "sectionnumber": 1,
      "name": "Section 1",
      "summary": "Mô tả section 1"
    },
    {
      "sectionid": 457,
      "sectionnumber": 2,
      "name": "Section 2",
      "summary": "Mô tả section 2"
    }
  ],
  "warnings": []
}
```

### Error Response (with warnings)

```json
{
  "sections": [
    {
      "sectionid": 456,
      "sectionnumber": 1,
      "name": "Section 1",
      "summary": "Mô tả section 1"
    }
  ],
  "warnings": [
    {
      "index": 1,
      "warningcode": "toomanysections",
      "message": "Cannot create more sections. Maximum is 52."
    }
  ]
}
```

## Lưu ý

1. **Token**: Thay `YOUR_TOKEN` bằng token thực tế từ Moodle Web Service
2. **URL**: Thay `https://your-moodle-site.com` bằng URL Moodle của bạn
3. **Course ID**: Thay `123` bằng ID khóa học thực tế
4. **Position**: 
   - `0` = Thêm vào cuối khóa học
   - `1, 2, 3...` = Thêm vào vị trí cụ thể
5. **Summary Format**:
   - `0` = MOODLE
   - `1` = HTML (mặc định)
   - `2` = PLAIN
   - `4` = MARKDOWN
6. **Visible**:
   - `0` = Ẩn section
   - `1` = Hiển thị section (mặc định)

## Capabilities Required

- `moodle/course:update` - Để tạo section
- `moodle/course:movesections` - Để chèn section vào vị trí cụ thể (nếu position > 0)

