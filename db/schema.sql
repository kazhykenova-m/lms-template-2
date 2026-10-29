-- Clean schema derived from ver-29-10. Import into an EMPTY database selected by your host.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS departments (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS roles (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    dept_id INT NOT NULL,
    name    VARCHAR(100) NOT NULL,
    FOREIGN KEY (dept_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(191) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    first_name    VARCHAR(100) NOT NULL,
    last_name     VARCHAR(100) NOT NULL,
    dept_id       INT,
    role_id       INT,
    is_admin      TINYINT(1) DEFAULT 0,
    avatar VARCHAR(500) DEFAULT NULL,
    is_manager TINYINT(1) DEFAULT 0,
    visible_skill_level_ids TEXT DEFAULT NULL,
    assigned_skill_level_id INT DEFAULT NULL,
    onboarding_dept_id INT DEFAULT NULL,
    onboarding_role_id INT DEFAULT NULL,
    coins         INT DEFAULT 0,
    level         INT DEFAULT 1,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dept_id) REFERENCES departments(id) ON DELETE SET NULL,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL
) ENGINE=InnoDB;



CREATE TABLE IF NOT EXISTS tests (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    dept_id      INT NOT NULL,
    title        VARCHAR(255) NOT NULL,
    description  TEXT,
    content_type ENUM('quiz','video','task') DEFAULT 'quiz',
    video_path   VARCHAR(500) DEFAULT '',
    coins_reward INT DEFAULT 0,
    role_id INT DEFAULT 1,
    skill_level_id INT DEFAULT NULL,
    onboarding_step_id INT DEFAULT NULL,
    questions_to_issue INT NOT NULL DEFAULT 10,
    is_active    TINYINT(1) DEFAULT 1,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dept_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS questions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    test_id       INT NOT NULL,
    sort          INT DEFAULT 0,
    type          ENUM('single','multi','open') DEFAULT 'single',
    question_text TEXT NOT NULL,
    allow_own_answer TINYINT(1) DEFAULT 0,
    step_id INT DEFAULT 0,
    FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS question_options (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    option_text TEXT NOT NULL,
    is_correct  TINYINT(1) DEFAULT 0,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS test_results (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    test_id      INT NOT NULL,
    score        INT DEFAULT 0,
    max_score    INT DEFAULT 0,
    passed       TINYINT(1) NOT NULL DEFAULT 0,
    answers_json LONGTEXT,
    attempt INT DEFAULT 1,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    review_status VARCHAR(20) DEFAULT 'completed',
    completed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE
) ENGINE=InnoDB;









INSERT IGNORE INTO departments(id,slug,name) VALUES(1,'workspace','Общее пространство');
INSERT IGNORE INTO roles(id,dept_id,name) VALUES(1,1,'Пользователь');
CREATE TABLE IF NOT EXISTS skill_levels (
 id INT AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(191) NOT NULL UNIQUE, title VARCHAR(255) NOT NULL,
 description TEXT, coins_required INT DEFAULT 0, questions_count INT DEFAULT 10,
 duration_minutes INT DEFAULT 60, assignments_count INT DEFAULT 1, sort INT DEFAULT 1, is_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS skill_level_attempts (
 id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,skill_level_id INT NOT NULL,
 dept_id INT DEFAULT 1,role_id INT DEFAULT 1,question_ids LONGTEXT,assignment_ids LONGTEXT,
 started_at DATETIME NOT NULL,expires_at DATETIME NOT NULL,completed_at DATETIME DEFAULT NULL,
 elapsed_seconds INT DEFAULT NULL,status VARCHAR(20) DEFAULT 'active',is_current TINYINT(1) DEFAULT 1,
 INDEX user_level(user_id,skill_level_id,is_current)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS skill_level_results (
 id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,skill_level_id INT NOT NULL,test_id INT DEFAULT NULL,
 dept_id INT DEFAULT 1,role_id INT DEFAULT 1,score INT DEFAULT 0,max_score INT DEFAULT 0,passed TINYINT(1) DEFAULT 0,
 question_ids LONGTEXT,answers_json LONGTEXT,review_status VARCHAR(20) DEFAULT 'completed',
 completed_at DATETIME DEFAULT CURRENT_TIMESTAMP,is_current TINYINT(1) DEFAULT 1,
 INDEX user_level(user_id,skill_level_id,is_current)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS achievements (
 id INT AUTO_INCREMENT PRIMARY KEY,slug VARCHAR(191) NOT NULL UNIQUE,title VARCHAR(255) NOT NULL,
 description TEXT,icon VARCHAR(50) DEFAULT '🏆',icon_svg TEXT,condition_type VARCHAR(30) DEFAULT 'skill_level',
 condition_value VARCHAR(255) DEFAULT '',is_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS user_achievements (
 user_id INT NOT NULL,achievement_id INT NOT NULL,earned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id,achievement_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY(achievement_id) REFERENCES achievements(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS platform_settings (
 id INT PRIMARY KEY,settings_json LONGTEXT NOT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO platform_settings VALUES(1,'{}');
