<?php

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/custom_service/externallib.php');
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/label/lib.php');
require_once($CFG->dirroot . '/enrol/manual/externallib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->libdir . '/accesslib.php');
require_once($CFG->dirroot . '/backup/externallib.php');
require_once($CFG->dirroot . '/course/externallib.php');
function create_course_with_json_step_1($dataJson) {
    global $DB;
    try {
        // Kiểm tra các tham số đầu vào
        if (empty($dataJson['fullname'])) {
            throw new Exception('Course fullname is required.');
        }

        if (empty($dataJson['shortname'])) {
            throw new Exception('Course shortname is required.');
        }

        if (empty($dataJson['categoryid'])) {
            throw new Exception('Category ID is required.');
        }

        if (empty($dataJson['format'])) {
            $dataJson['format'] = 'topics';
        }

        if (empty($dataJson['numsection'])) {
            throw new Exception('Number of sections is required.');
        }

        if (empty($dataJson['topicdetail'])) {
            throw new Exception('Topicdetail is required.');
        }
    
        $decoded_data = json_decode($dataJson['topicdetail'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON for topicdetail: ' . json_last_error_msg());
        }

        // Define the required keys for topicdetail and base activity keys
        $topicdetail_keys = ['name', 'description'];

        // Loop through each topic and check for the required keys and values
        foreach ($decoded_data as $topic) {
            // Check keys for topic
            if (!empty(array_diff($topicdetail_keys, array_keys($topic)))) {
                throw new Exception('Topic is missing required fields.');
            }

            // Check if the 'name' field in topic is not empty
            if (empty($topic['name'])) {
                throw new Exception('Topic name cannot be empty.');
            }
        }

        // core_course_external::delete_modules($cmids);

        $existing_course = $DB->get_record('course', ['shortname' => $dataJson['shortname']]);
        
        if ($existing_course) {
            $existingCourseId = $existing_course->id;

            $checkStudentOnCourseSql = "SELECT u.id 
                FROM {user} u
                JOIN {role_assignments} ra ON ra.userid = u.id
                JOIN {context} ctx ON ctx.id = ra.contextid
                JOIN {role} r ON r.id = ra.roleid
                WHERE ctx.contextlevel = 50 
                AND ctx.instanceid = :courseid
                AND r.shortname = :roleshortname";

            $students = $DB->get_records_sql($checkStudentOnCourseSql, [
                'courseid' => $existingCourseId,
                'roleshortname' => 'student' // Thay bằng role shortname mong muốn
            ]);

            if (!empty($students)) {
                throw new Exception('Failed to update the course because it already has students.');
            }

            // $modules = $DB->get_records('course_modules', ['course' => $existingCourseId]);

            // $cmids = [];
            // foreach ($modules as $module) {
            //     $cmids[] = $module->id;
            // }

            // check exist course backup
            $shortNameBackup = $dataJson['shortname'] . '_backup_n8n';
            ob_start();
            $existing_course_backup = $DB->get_record('course', ['shortname' => $shortNameBackup]);

            if($existing_course_backup){
                $courseids = [
                    $existing_course_backup->id
                ];
                core_course_external::delete_courses($courseids);
            }
            ob_end_clean();
            $existingCourseId = $existing_course->id;

            $courseUpdate = new stdClass();
            $courseUpdate->id = $existingCourseId;
            $courseUpdate->fullname = $dataJson['fullname'] . '_backup_n8n';
            $courseUpdate->shortname = $dataJson['shortname'] . '_backup_n8n';
            // $courseUpdate->category = $dataJson['categoryid'];
            // $courseUpdate->summary = $dataJson['summary'];
            // $courseUpdate->summary = 'description';
            // $courseUpdate->format = $dataJson['format'];
            // $courseUpdate->numsections = $dataJson['numsection'];
            // $courseUpdate->startdate = time();
            $courseUpdate->visible = 0; // Mặc định khóa học có thể truy cập
            // Tạo khóa học thông qua API Moodle
            update_course($courseUpdate);
        }

        $course = new stdClass();
        $course->fullname = $dataJson['fullname'];
        $course->shortname = $dataJson['shortname'];
        $course->category = $dataJson['categoryid'];
        // $course->summary = $dataJson['summary'];
        $course->summary = 'description';
        $course->format = $dataJson['format'];
        $course->numsections = $dataJson['numsection'];
        $course->startdate = time();
        $course->enablecompletion = 1;
        $course->visible = 1; // Mặc định khóa học có thể truy cập
        // Tạo khóa học thông qua API Moodle
        $course = create_course($course);
        // Kiểm tra nếu có lỗi trong quá trình tạo khóa học
        if (isset($course->errorcode)) {
            throw new Exception($course->message);
        }

        $courseId = $course->id;

        $sectionNumGeneral = 0;
        $summarySectionGeneral = base64_encode($dataJson['summary']);
        $dataJsonSectionGeneral = [
            'courseid' => (int)$courseId,
            'sections' => [
                [
                    'type' => 'num',
                    'section' => (int)$sectionNumGeneral,
                    'name' => 'General',
                    'summary' => $summarySectionGeneral,
                    'visible' => 1
                ]
            ]
        ];

        $jsonEncodedSectionGeneral = json_encode($dataJsonSectionGeneral, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            
        $sectionDataSectionGeneral = base64_encode($jsonEncodedSectionGeneral);

        $sectionActionUpdateGeneral = local_custom_service_external::update_sections($sectionDataSectionGeneral);

        if(isset($sectionActionUpdateGeneral['warnings'][0]['warningcode'])){
            throw new Exception('Update general information has failed. Please check the content and try again.');
        }
        
        // Thêm chủ đề và hoạt động vào khóa học
        foreach ($decoded_data as $key => $topic) {
            $summarySection = base64_encode($topic['description']);
            $sectionNum = (int)$key + 1;
            $dataJsonSection = [
                'courseid' => (int)$courseId,
                'sections' => [
                    [
                        'type' => 'num',
                        'section' => $sectionNum,
                        'name' => $topic['name'],
                        'summary' => $summarySection,
                        'visible' => 1
                    ]
                ]
            ];

            $jsonEncoded = json_encode($dataJsonSection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            
            $sectionData = base64_encode($jsonEncoded);

            $sectionActionUpdate = local_custom_service_external::update_sections($sectionData);

            if (!empty($sectionActionUpdate['warnings'][0]['warningcode'])) {
                $errorMessage = $sectionActionUpdate['warnings'][0]['message'] ?? 'Unknown error';
                $sectionName = $topic['name'] ?? 'Unnamed section';
                throw new Exception('Update section with name "' . $sectionName . '" failed. Error: ' . $errorMessage);
            }
        }

        $role_shortname = 'editingteacher';

        // Lấy thông tin role từ DB.
        $role = $DB->get_record('role', ['shortname' => $role_shortname], 'id, shortname');

        if ($role) {
            $role_id = $role->id;
            local_custom_service_external::enrol_user_to_course($dataJson['useridlms'], $courseId, $role_id);
        } else {
            throw new Exception("Role '{$role_shortname}' does not exist in the database.");
        }

        return [
            'status' => true,
            'message' => 'Course created successfully.',
            'data' => [
                'id' => $courseId
            ]
        ];

    } catch (Exception $e) {

        return [
            'status' => false,
            'message' => $e->getMessage(),
            'data' => []
        ];
    }
}

function create_course_with_json_step_2($dataJson) {
    global $DB;
    try {
        // Kiểm tra các tham số đầu vào
        if (empty($dataJson['fullname'])) {
            throw new Exception('Course fullname is required.');
        }

        if (empty($dataJson['shortname'])) {
            throw new Exception('Course shortname is required.');
        }

        if (empty($dataJson['activitydetail'])) {
            throw new Exception('Activitydetail is required.');
        }
    
        $decoded_data = json_decode($dataJson['activitydetail'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON for activitydetail: ' . json_last_error_msg());
        }

        // Define the required keys for activitydetail and base activity keys
        $activitydetail_keys = ['title', 'type', 'topic'];

        // Loop through each topic and check for the required keys and values
        foreach ($decoded_data as $activity) {

            $required_activity_keys = $activitydetail_keys;

            if (isset($activity['type']) && in_array($activity['type'], ['page', 'url'])) {
                $required_activity_keys[] = 'content';
            }

            if (!empty(array_diff($required_activity_keys, array_keys($activity)))) {
                throw new Exception('Activity is missing required fields for type: ' . ($activity['type'] ?? 'unknown'));
            }

            if (empty($activity['title'])) {
                throw new Exception('Activity name cannot be empty.');
            }

            if (empty($activity['type'])) {
                throw new Exception('Activity type cannot be empty.');
            }

            if (empty($activity['topic'])) {
                throw new Exception('Activity type cannot be empty.');
            }

            // Additional validation for 'content' if type is 'page'
            if (in_array($activity['type'], ['page', 'url']) && empty($activity['content'])) {
                throw new Exception('Activity content cannot be empty for type ' . $activity['type'] . '.');
            }

            if ($activity['type'] === 'url' && !filter_var($activity['content'], FILTER_VALIDATE_URL)) {
                throw new Exception('Activity content must be a valid URL for type url.');
            }

            // if ($activity['type'] === 'quiz' && !filter_var($activity['content'], FILTER_VALIDATE_URL)) {
            //     throw new Exception('Activity content must be a valid URL for type "url".');
            // }
        }

        $existing_course = $DB->get_record('course', ['shortname' => $dataJson['shortname']]);

        $courseId = $existing_course->id;

        if (!$existing_course) {
            throw new Exception('A course with the shortname ' . $dataJson['shortname'] . ' does not exists.');
        }else{
            foreach ($decoded_data as $key => $activity) {
                $activityName = $activity['title'];
                $activityType = $activity['type'];
                $activityContent = $activity['content'];
                $activityDescription = $activity['description'];
                $activityVisible = 1;
                $sectionNum = $activity['topic'];

                if($activityType == 'forum'){
                    $activityDisplay = 1;
                    $createForum = local_custom_service_external::create_activity_label($courseId, $activityContent, $activityName, $activityType, $sectionNum, $activityDisplay, $activityVisible, $activityDescription);
                
                    if($createForum['instanceid']){
                        $dataAddNewPostForum = [
                            'forum_id' => $createForum['instanceid'],
                            'useridlms' => $dataJson['useridlms'],
                            'course_id' => $courseId,
                            'subject' => $activity['subject'] ?? 'Post Forum',
                            'message' => $activity['message'] ?? 'Message Post Forum',
                        ];
    
                        $add_new_post_forum = add_new_post_forum($dataAddNewPostForum);
    
                        if(!$add_new_post_forum['status']){
                            throw new Exception($add_new_post_forum['message']);
                        }
                    }
                }

                if($activityType == 'page'){
                    $activityDisplay = 5;
                    local_custom_service_external::create_activity_label($courseId, $activityContent, $activityName, $activityType, $sectionNum, $activityDisplay, $activityVisible, $activityDescription);
                }

                if($activityType == 'url'){
                    $activityDisplay = 6;
                    local_custom_service_external::create_activity_label($courseId, $activityContent, $activityName, $activityType, $sectionNum, $activityDisplay, $activityVisible, $activityDescription);
                }

                if($activityType == 'assign'){
                    $activityDisplay = 1;
                    local_custom_service_external::create_activity_label($courseId, $activityDescription, $activityName, $activityType, $sectionNum, $activityDisplay, $activityVisible);
                }
                
                if($activityType == 'book'){
                    $activityCode = $activity['code'] ?? '';
                    $chapters = json_encode($activity['chapters'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    local_custom_service_external::create_activity_book($courseId, $activityName, $sectionNum, $activityDescription, $chapters, $activityCode);
                }

                // if($activityType == 'quiz'){
                //     local_custom_service_external::create_activity_quiz($courseId, $activityName, $sectionNum, $activityDescription);
                // }
            }
        }

        return [
            'status' => true,
            'message' => 'Course created successfully.',
            'data' => [
                'id' => $courseId
            ]
        ];

    } catch (Exception $e) {

        return [
            'status' => false,
            'message' => $e->getMessage(),
            'data' => []
        ];
    }
}

function add_new_post_forum($dataJson) {
    global $DB;
    
    try {
        // Step 1: Insert into mdl_forum_posts
        $post = new stdClass();
        $post->parent = 0;
        $post->userid = $dataJson['useridlms'];
        $post->created = $post->modified = time();
        $post->mailed  = 0;
        $post->subject = $dataJson['subject'];
        $post->message = $dataJson['message'];
        $post->messageformat = 1;
        $post->messagetrust = 0;
        $post->attachment = "";
        $post->privatereplyto = 0;
        $post->wordcount = count_words($dataJson['subject']);
        $post->charcount = count_letters($dataJson['message']);
        $post->mailnow    = 0;
        $post->totalscore = 0;

        $post->id = $DB->insert_record("forum_posts", $post);
        if (!$post->id) {
            throw new Exception('Failed to insert post into forum_posts table');
        }

        // Step 2: Insert into mdl_forum_discussions
        $discussion = new stdClass();
        $discussion->course       = $dataJson['course_id'];
        $discussion->forum        = $dataJson['forum_id'];
        $discussion->name         = $dataJson['subject'];
        $discussion->firstpost    = $post->id;
        $discussion->userid       = $dataJson['useridlms'];
        $discussion->groupid      = '-1';
        $discussion->firstpost    = $post->id;
        $discussion->timemodified = time();
        $discussion->usermodified = $dataJson['useridlms'];
        $discussion->assessed     = 0;

        $discussion->id = $DB->insert_record("forum_discussions", $discussion);
        if (!$discussion->id) {
            throw new Exception('Failed to insert discussion into forum_discussions table');
        }

        // Step 3: Update mdl_forum_posts with the discussion ID
        $post->discussion = $discussion->id; // Assign the discussion ID to the post
        $DB->update_record("forum_posts", $post); // Update the post record

        // Step 4: Insert into mdl_forum_discussion_subs
        $subscription = new stdClass();
        $subscription->forum = $dataJson['forum_id']; // Forum ID
        $subscription->discussion = $discussion->id; // Discussion ID
        $subscription->userid = $dataJson['useridlms']; // User ID
        $subscription->preference = time(); // Current time

        $DB->insert_record("forum_discussion_subs", $subscription); // Insert subscription

        return [
            'status' => true,
            'message' => 'Successfully'
        ];

    } catch (Exception $e) {
        // Handle the error, log it, or return a message
        return [
            'status' => false,
            'message' => $e->getMessage()
        ];
    }
}

function save_question_type_truefalse($dataJson){
    $question = new stdClass();
    $question->category = $dataJson['question_category']; // Danh mục của câu hỏi
    $question->qtype = $dataJson['question_qtype']; // Loại câu hỏi
    $question->createdby = $dataJson['question_createdby']; // ID người tạo câu hỏi
    $question->formoptions = new stdClass(); // Các tùy chọn của biểu mẫu
    $question->formoptions->canedit = true; // Có thể chỉnh sửa
    $question->formoptions->canmove = true; // Có thể di chuyển
    $question->formoptions->cansaveasnew = false; // Không thể lưu dưới dạng mới
    $question->formoptions->repeatelements = true; // Có thể lặp lại các phần tử
    $question->formoptions->mustbeusable = true; // Phải có thể sử dụng
    $question->contextid = $dataJson['question_contextid']; // ID ngữ cảnh

    if($dataJson['question_qtype'] == 'truefalse'){
        $form = form_data_question_for_type_truefalse($dataJson);
    }

    if($dataJson['question_qtype'] == 'multichoice'){
        $form = form_data_question_for_type_multichoice($dataJson);
    }

    if($dataJson['question_qtype'] == 'match'){
        $form = form_data_question_for_type_match($dataJson);
    }

    if($dataJson['question_qtype'] == 'ddwtos'){
        $form = form_data_question_for_type_ddwtos($dataJson);
    }
    
    $questionbank = question_bank::get_qtype($dataJson['question_qtype'])->save_question($question, $form);

    return $questionbank;
}

function form_data_question_for_type_truefalse($dataJson){
    $form = new stdClass();
    $form->category = $dataJson['form_category']; // Danh mục câu hỏi
    $form->name = $dataJson['form_name']; // Tên câu hỏi
    $form->questiontext = [
        'text' => $dataJson['form_questiontext'],
        'format' => "1",
        // 'itemid' => 425874742,
    ]; // Nội dung câu hỏi
    $form->status = 'ready'; // Trạng thái
    $form->defaultmark = 1.0; // Điểm mặc định

    $form->generalfeedback = [
        'text' => "",
        'format' => "1",
        // 'itemid' => 440936844,
    ]; // Phản hồi chung

    $form->idnumber = ''; // Số hiệu ID câu hỏi
    $form->correctanswer = $dataJson['form_correctanswer']; // Đáp án đúng
    $form->showstandardinstruction = '0'; // Hiển thị hướng dẫn tiêu chuẩn

    $form->feedbacktrue = [
        'text' => "",
        'format' => "1",
        // 'itemid' => 888858070,
    ]; // Phản hồi nếu đúng

    $form->feedbackfalse = [
        'text' => "",
        'format' => "1",
        // 'itemid' => 477126511,
    ]; // Phản hồi nếu sai

    $form->penalty = 1.0; // Điểm phạt
    $form->tags = []; // Thẻ câu hỏi

    $form->id = 0; // ID câu hỏi
    $form->inpopup = 0; // Hiển thị popup
    $form->cmid = $dataJson['form_cmid']; // ID module khóa học
    $form->courseid = $dataJson['form_courseid']; // ID khóa học
    $form->returnurl = $dataJson['form_returnurl']; // URL trả về
    $form->mdlscrollto = 0; // Vị trí cuộn
    $form->appendqnumstring = 'addquestion'; // Chuỗi bổ sung câu hỏi
    $form->qtype = $dataJson['question_qtype']; // Loại câu hỏi
    $form->makecopy = 0; // Tạo bản sao
    $form->submitbutton = 'Save changes'; // Nút lưu
    $form->modulename = 'mod_quiz'; // Tên module

    return $form;
}

function form_data_question_for_type_multichoice($dataJson){
    $form = new stdClass();
    $form->category = $dataJson['form_category']; // Danh mục câu hỏi
    $form->name = $dataJson['form_name']; // Tên câu hỏi
    $form->questiontext = [
        'text' => $dataJson['form_questiontext'],
        'format' => "1",
    ];
    $form->status = 'ready'; // Trạng thái
    $form->defaultmark = 1; // Điểm mặc định

    $form->generalfeedback = [
        'text' => "",
        'format' => "1",
    ];

    $form->idnumber = ''; // Số hiệu ID câu hỏi
    $form->single = $dataJson['form_single']; // 0: mutiple answer, 1: one answer
    $form->shuffleanswers = 1;
    $form->answernumbering = 'abc';
    $form->showstandardinstruction = 0;
    $form->mform_isexpanded_id_answerhdr = 1;
    $form->noanswers = $dataJson['form_noanswers'];
    $form->answer = $dataJson['form_answer'];
    $form->fraction = $dataJson['form_fraction'];
    $form->feedback = $dataJson['form_feedback'];
    $form->correctfeedback = [
        'text' => "<p>Your answer is correct.</p>",
        'format' => "1",
    ];
    $form->partiallycorrectfeedback = [
        'text' => "<p>Your answer is partially correct.</p>",
        'format' => "1",
    ];
    $form->shownumcorrect = 1;
    $form->incorrectfeedback = [
        'text' => "<p>Your answer is incorrect.</p>",
        'format' => "1",
    ];
    $form->penalty = '0.3333333';
    $form->numhints = 2;
    $form->hint = [
        [
            "text" => "",
            "format" => 1,
        ],
        [
            "text" => "",
            "format" => 1,
        ]
    ];
    $form->hintclearwrong = [
        "0",
        "0"
    ];
    $form->hintshownumcorrect = [
        "0",
        "0"
    ];
    $form->tags = [];
    $form->id = 0; // ID câu hỏi
    $form->inpopup = 0; // Hiển thị popup
    $form->cmid = $dataJson['form_cmid']; // ID module khóa học
    $form->courseid = $dataJson['form_courseid']; // ID khóa học
    $form->returnurl = $dataJson['form_returnurl']; // URL trả về
    $form->mdlscrollto = 0; // Vị trí cuộn
    $form->appendqnumstring = 'addquestion'; // Chuỗi bổ sung câu hỏi
    $form->qtype = $dataJson['question_qtype']; // Loại câu hỏi
    $form->makecopy = 0; // Tạo bản sao
    $form->submitbutton = 'Save changes'; // Nút lưu
    $form->modulename = 'mod_quiz'; // Tên module

    return $form;
}

function form_data_question_for_type_match($dataJson){
    $form = new stdClass();
    $form->category = $dataJson['form_category']; // Danh mục câu hỏi
    $form->name = $dataJson['form_name']; // Tên câu hỏi
    $form->questiontext = [
        'text' => $dataJson['form_questiontext'],
        'format' => "1",
    ];
    $form->status = 'ready'; // Trạng thái
    $form->defaultmark = 1; // Điểm mặc định
    $form->generalfeedback = [
        'text' => "",
        'format' => "1",
    ];
    $form->idnumber = ''; // Số hiệu ID câu hỏi
    $form->shuffleanswers = 1;
    $form->mform_isexpanded_id_answerhdr = 1;
    $form->noanswers = $dataJson['form_noanswers'];
    $form->subquestions = $dataJson['form_subquestions'];
    $form->subanswers = $dataJson['form_subanswers'];
    $form->correctfeedback = [
        'text' => "<p>Your answer is correct.</p>",
        'format' => "1",
    ];
    $form->partiallycorrectfeedback = [
        'text' => "<p>Your answer is partially correct.</p>",
        'format' => "1",
    ];
    $form->shownumcorrect = 1;
    $form->incorrectfeedback = [
        'text' => "<p>Your answer is incorrect.</p>",
        'format' => "1",
    ];
    $form->penalty = '0.3333333';
    $form->numhints = 2;
    $form->hint = [
        [
            "text" => "",
            "format" => 1,
        ],
        [
            "text" => "",
            "format" => 1,
        ]
    ];
    $form->hintclearwrong = [
        "0",
        "0"
    ];
    $form->hintshownumcorrect = [
        "0",
        "0"
    ];
    $form->tags = [];
    $form->id = 0; // ID câu hỏi
    $form->inpopup = 0; // Hiển thị popup
    $form->cmid = $dataJson['form_cmid']; // ID module khóa học
    $form->courseid = $dataJson['form_courseid']; // ID khóa học
    $form->returnurl = $dataJson['form_returnurl']; // URL trả về
    $form->mdlscrollto = 0; // Vị trí cuộn
    $form->appendqnumstring = 'addquestion'; // Chuỗi bổ sung câu hỏi
    $form->qtype = $dataJson['question_qtype']; // Loại câu hỏi
    $form->makecopy = 0; // Tạo bản sao
    $form->submitbutton = 'Save changes'; // Nút lưu
    $form->modulename = 'mod_quiz'; // Tên module

    return $form;
}

function form_data_question_for_type_ddwtos($dataJson){
    $form = new stdClass();
    $form->category = $dataJson['form_category']; // Danh mục câu hỏi
    $form->name = $dataJson['form_name']; // Tên câu hỏi
    $form->questiontext = $dataJson['form_ddwtos_texts'];
    $form->status = 'ready'; // Trạng thái
    $form->defaultmark = 1; // Điểm mặc định
    $form->generalfeedback = [
        'text' => "",
        'format' => "1",
    ];
    $form->idnumber = ''; // Số hiệu ID câu hỏi
    $form->mform_isexpanded_id_choicehdr = 1;
    $form->noanswers = $dataJson['form_noanswers'];
    $form->choices = $dataJson['form_ddwtos_answers'];
    $form->correctfeedback = [
        'text' => "<p>Your answer is correct.</p>",
        'format' => "1",
    ];
    $form->partiallycorrectfeedback = [
        'text' => "<p>Your answer is partially correct.</p>",
        'format' => "1",
    ];
    $form->shownumcorrect = 1;
    $form->incorrectfeedback = [
        'text' => "<p>Your answer is incorrect.</p>",
        'format' => "1",
    ];
    $form->penalty = '0.3333333';
    $form->numhints = 2;
    $form->hint = [
        [
            "text" => "",
            "format" => 1,
        ],
        [
            "text" => "",
            "format" => 1,
        ]
    ];
    $form->hintclearwrong = [
        "0",
        "0"
    ];
    $form->hintshownumcorrect = [
        "0",
        "0"
    ];
    $form->tags = [];
    $form->id = 0; // ID câu hỏi
    $form->inpopup = 0; // Hiển thị popup
    $form->cmid = $dataJson['form_cmid']; // ID module khóa học
    $form->courseid = $dataJson['form_courseid']; // ID khóa học
    $form->returnurl = $dataJson['form_returnurl']; // URL trả về
    $form->mdlscrollto = 0; // Vị trí cuộn
    $form->appendqnumstring = 'addquestion'; // Chuỗi bổ sung câu hỏi
    $form->qtype = $dataJson['question_qtype']; // Loại câu hỏi
    $form->makecopy = 0; // Tạo bản sao
    $form->submitbutton = 'Save changes'; // Nút lưu
    $form->modulename = 'mod_quiz'; // Tên module

    return $form;
}

function execute_curl_custome_service_function($url, $data = false, $contentType = false, $token = false, $method)
{
    $ch = curl_init();
    $headers = array();
    if ($contentType) {
        $headers[] = $contentType;
    }
    if ($token) {
        $headers[] = $token;
    }
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error: ' . curl_error($ch);
    }
    curl_close($ch);
    return $response;
}

function get_data_slide_by_code($data)
{
    global $CFG;
    require_once($CFG->dirroot . '/local/custom_service/config/config.php');
    $url = $urlApiWp;
    $method = 'POST';
    $response = json_decode(execute_curl_custome_service_function($url, $data, false, false, $method), true);
    return $response;
}

function get_user_email_by_school_id($schoolId)
{
    global $CFG;
    require_once($CFG->dirroot . '/local/custom_service/config/config.php');
    $url = $urlApiGetUserBySchoolId . '?schoolId=' . $schoolId;
    $method = 'GET';
    $response = json_decode(execute_curl_custome_service_function($url, false, false, false, $method), true);
    return $response;
}