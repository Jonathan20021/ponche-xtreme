-- Migration: Add Recruitment System
-- Description: Creates tables for job applications, recruitment process, interviews, and status tracking

-- Table for job postings
CREATE TABLE IF NOT EXISTS job_postings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    department VARCHAR(100),
    location VARCHAR(100),
    employment_type ENUM('full_time', 'part_time', 'contract', 'internship') DEFAULT 'full_time',
    description TEXT,
    requirements TEXT,
    responsibilities TEXT,
    salary_range VARCHAR(100),
    status ENUM('active', 'inactive', 'closed') DEFAULT 'active',
    posted_date DATE NOT NULL,
    closing_date DATE,
    created_by INT(10) UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for job applications
CREATE TABLE IF NOT EXISTS job_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_code VARCHAR(50) UNIQUE NOT NULL,
    job_posting_id INT,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    address TEXT,
    city VARCHAR(100),
    state VARCHAR(100),
    country VARCHAR(100),
    postal_code VARCHAR(20),
    date_of_birth DATE,
    education_level VARCHAR(100),
    years_of_experience INT,
    current_position VARCHAR(255),
    current_company VARCHAR(255),
    expected_salary VARCHAR(100),
    availability_date DATE,
    cv_filename VARCHAR(255),
    cv_path VARCHAR(500),
    cover_letter TEXT,
    linkedin_url VARCHAR(500),
    portfolio_url VARCHAR(500),
    status ENUM('new', 'reviewing', 'shortlisted', 'interview_scheduled', 'interviewed', 'offer_extended', 'hired', 'rejected', 'withdrawn') DEFAULT 'new',
    overall_rating INT CHECK (overall_rating >= 1 AND overall_rating <= 5),
    applied_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    assigned_to INT(10) UNSIGNED,
    FOREIGN KEY (job_posting_id) REFERENCES job_postings(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_email (email),
    INDEX idx_status (status),
    INDEX idx_application_code (application_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for application comments/notes
CREATE TABLE IF NOT EXISTS application_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    user_id INT(10) UNSIGNED NOT NULL,
    comment TEXT NOT NULL,
    is_internal BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES job_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for interview schedules
CREATE TABLE IF NOT EXISTS recruitment_interviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    interview_type ENUM('phone_screening', 'technical', 'hr', 'manager', 'final', 'other') DEFAULT 'hr',
    interview_date DATETIME NOT NULL,
    duration_minutes INT DEFAULT 60,
    location VARCHAR(255),
    meeting_link VARCHAR(500),
    interviewer_ids TEXT,
    status ENUM('scheduled', 'completed', 'cancelled', 'rescheduled', 'no_show') DEFAULT 'scheduled',
    notes TEXT,
    feedback TEXT,
    rating INT CHECK (rating >= 1 AND rating <= 5),
    created_by INT(10) UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES job_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for application status history
CREATE TABLE IF NOT EXISTS application_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    old_status VARCHAR(50),
    new_status VARCHAR(50) NOT NULL,
    changed_by INT(10) UNSIGNED,
    notes TEXT,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES job_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for applicant skills
CREATE TABLE IF NOT EXISTS applicant_skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    skill_name VARCHAR(100) NOT NULL,
    proficiency_level ENUM('beginner', 'intermediate', 'advanced', 'expert') DEFAULT 'intermediate',
    years_experience INT,
    FOREIGN KEY (application_id) REFERENCES job_applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for applicant references
CREATE TABLE IF NOT EXISTS applicant_references (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    reference_name VARCHAR(255) NOT NULL,
    reference_company VARCHAR(255),
    reference_position VARCHAR(255),
    reference_email VARCHAR(255),
    reference_phone VARCHAR(20),
    relationship VARCHAR(100),
    contacted BOOLEAN DEFAULT FALSE,
    notes TEXT,
    FOREIGN KEY (application_id) REFERENCES job_applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample job postings
INSERT INTO job_postings (title, department, location, employment_type, description, requirements, responsibilities, salary_range, status, posted_date, closing_date) VALUES
('Desarrollador Full Stack', 'Tecnología', 'Ciudad de México', 'full_time', 
'Buscamos un desarrollador full stack con experiencia en PHP, JavaScript y bases de datos MySQL para unirse a nuestro equipo de desarrollo.',
'- 3+ años de experiencia en desarrollo web\n- Dominio de PHP, JavaScript, HTML, CSS\n- Experiencia con MySQL\n- Conocimiento de Git\n- Inglés intermedio',
'- Desarrollar y mantener aplicaciones web\n- Colaborar con el equipo de diseño\n- Optimizar el rendimiento de las aplicaciones\n- Participar en revisiones de código',
'$25,000 - $35,000 MXN', 'active', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY)),

('Especialista en Recursos Humanos', 'Recursos Humanos', 'Ciudad de México', 'full_time',
'Buscamos un especialista en RRHH para gestionar el proceso de reclutamiento y desarrollo del personal.',
'- Licenciatura en Recursos Humanos o afín\n- 2+ años de experiencia en reclutamiento\n- Excelentes habilidades de comunicación\n- Conocimiento de leyes laborales mexicanas',
'- Gestionar procesos de reclutamiento\n- Realizar entrevistas\n- Coordinar capacitaciones\n- Administrar expedientes de personal',
'$20,000 - $28,000 MXN', 'active', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 45 DAY)),

('Analista de Datos', 'Operaciones', 'Remoto', 'full_time',
'Buscamos un analista de datos para transformar datos en insights accionables.',
'- Licenciatura en Matemáticas, Estadística o afín\n- Experiencia con SQL y Excel avanzado\n- Conocimiento de herramientas de visualización\n- Pensamiento analítico',
'- Analizar datos operacionales\n- Crear reportes y dashboards\n- Identificar tendencias y patrones\n- Presentar hallazgos a la gerencia',
'$22,000 - $30,000 MXN', 'active', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY));
