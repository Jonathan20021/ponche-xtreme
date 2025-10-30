-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 30-10-2025 a las 03:01:12
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `ponche`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `administrative_hours`
--

CREATE TABLE `administrative_hours` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `type` varchar(50) NOT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `admin_login_logs`
--

CREATE TABLE `admin_login_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `username` varchar(50) NOT NULL,
  `role` varchar(50) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `public_ip` varchar(45) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `login_time` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `admin_login_logs`
--

INSERT INTO `admin_login_logs` (`id`, `user_id`, `username`, `role`, `ip_address`, `public_ip`, `location`, `login_time`) VALUES
(1, 1, 'admin', 'Admin', '127.0.0.1', '170.80.202.31', 'Santiago de los Caballeros, Santiago Province, DO', '2025-10-28 17:13:22');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `attendance`
--

CREATE TABLE `attendance` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `type` varchar(50) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `attendance_types`
--

CREATE TABLE `attendance_types` (
  `id` int(11) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `label` varchar(120) NOT NULL,
  `icon_class` varchar(120) DEFAULT 'fas fa-circle',
  `shortcut_key` varchar(5) DEFAULT NULL,
  `color_start` varchar(7) NOT NULL DEFAULT '#6366f1',
  `color_end` varchar(7) NOT NULL DEFAULT '#4338ca',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_unique_daily` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `attendance_types`
--

INSERT INTO `attendance_types` (`id`, `slug`, `label`, `icon_class`, `shortcut_key`, `color_start`, `color_end`, `sort_order`, `is_unique_daily`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'ENTRY', 'Entry', 'fas fa-sign-in-alt', 'E', '#22C55E', '#16A34A', 10, 1, 1, '2025-10-28 15:07:21', '2025-10-28 17:13:36'),
(2, 'BREAK', 'Break', 'fas fa-coffee', 'B', '#3B82F6', '#2563EB', 20, 0, 1, '2025-10-28 15:07:21', '2025-10-28 17:13:36'),
(3, 'PAUSA', 'Pausa', 'fas fa-utensils', 'L', '#EAB308', '#CA8A04', 30, 0, 1, '2025-10-28 15:07:21', '2025-10-28 17:13:36'),
(4, 'WASAPI', 'Wasapi', 'fas fa-users', 'M', '#A855F7', '#7C3AED', 40, 0, 1, '2025-10-28 15:07:21', '2025-10-28 17:13:36'),
(5, 'DIGITACION', 'Digitacion', 'fas fa-tasks', 'F', '#6366F1', '#4338CA', 50, 0, 1, '2025-10-28 15:07:21', '2025-10-28 17:13:36'),
(6, 'BA_NO', 'Baño', 'fas fa-check', 'R', '#8B5CF6', '#6D28D9', 60, 0, 1, '2025-10-28 15:07:21', '2025-10-28 17:13:36'),
(7, 'EXIT', 'Exit', 'fas fa-sign-out-alt', 'X', '#EF4444', '#DC2626', 70, 1, 1, '2025-10-28 15:07:21', '2025-10-28 17:13:36');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `departments`
--

CREATE TABLE `departments` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `departments`
--

INSERT INTO `departments` (`id`, `name`, `description`, `created_at`, `updated_at`) VALUES
(1, 'Operations', 'Operations and service delivery', '2025-10-28 20:34:36', NULL),
(2, 'Human Resources', 'People and talent management', '2025-10-28 20:34:36', NULL),
(3, 'Technology', 'IT and systems team', '2025-10-28 20:34:36', NULL),
(4, 'Quality Assurance', 'QA and compliance', '2025-10-28 20:34:36', NULL),
(5, 'Client Services', 'Client success and account management', '2025-10-28 20:34:36', NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `employees`
--

CREATE TABLE `employees` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `employee_code` varchar(20) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `hire_date` date NOT NULL,
  `termination_date` date DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'República Dominicana',
  `emergency_contact_name` varchar(150) DEFAULT NULL,
  `emergency_contact_phone` varchar(20) DEFAULT NULL,
  `emergency_contact_relationship` varchar(50) DEFAULT NULL,
  `identification_type` varchar(50) DEFAULT NULL COMMENT 'Cédula, Pasaporte, etc.',
  `identification_number` varchar(50) DEFAULT NULL,
  `blood_type` varchar(5) DEFAULT NULL,
  `marital_status` varchar(20) DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `position` varchar(150) DEFAULT NULL,
  `department_id` int(10) UNSIGNED DEFAULT NULL,
  `supervisor_id` int(10) UNSIGNED DEFAULT NULL,
  `employment_status` varchar(50) NOT NULL DEFAULT 'ACTIVE' COMMENT 'ACTIVE, TRIAL, SUSPENDED, TERMINATED',
  `employment_type` varchar(50) DEFAULT 'FULL_TIME' COMMENT 'FULL_TIME, PART_TIME, CONTRACT, INTERN',
  `notes` text DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `employees`
--

INSERT INTO `employees` (`id`, `user_id`, `employee_code`, `first_name`, `last_name`, `email`, `phone`, `mobile`, `birth_date`, `hire_date`, `termination_date`, `address`, `city`, `state`, `postal_code`, `country`, `emergency_contact_name`, `emergency_contact_phone`, `emergency_contact_relationship`, `identification_type`, `identification_number`, `blood_type`, `marital_status`, `gender`, `position`, `department_id`, `supervisor_id`, `employment_status`, `employment_type`, `notes`, `profile_photo`, `created_at`, `updated_at`) VALUES
(1, 1, 'EMP-2025-0001', 'System', 'Administrator', NULL, NULL, NULL, '2002-02-15', '2025-10-28', NULL, NULL, NULL, NULL, NULL, 'República Dominicana', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, 'ACTIVE', 'FULL_TIME', NULL, NULL, '2025-10-29 20:14:26', '2025-10-29 20:59:41');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `employee_deductions`
--

CREATE TABLE `employee_deductions` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `deduction_config_id` int(11) DEFAULT NULL COMMENT 'NULL si es descuento personalizado',
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `type` enum('PERCENTAGE','FIXED') NOT NULL DEFAULT 'FIXED',
  `amount` decimal(10,2) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `employee_documents`
--

CREATE TABLE `employee_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `document_type` varchar(100) NOT NULL COMMENT 'CONTRACT, ID, CERTIFICATE, RESUME, etc.',
  `document_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `uploaded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `hourly_rate_history`
--

CREATE TABLE `hourly_rate_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `hourly_rate_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
  `hourly_rate_dop` decimal(12,2) NOT NULL DEFAULT 0.00,
  `effective_date` date NOT NULL COMMENT 'Fecha desde la cual esta tarifa es valida',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'Usuario que registro el cambio',
  `notes` text DEFAULT NULL COMMENT 'Notas sobre el cambio de tarifa'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Historial de cambios de tarifas por hora con fechas efectivas';

--
-- Volcado de datos para la tabla `hourly_rate_history`
--

INSERT INTO `hourly_rate_history` (`id`, `user_id`, `hourly_rate_usd`, `hourly_rate_dop`, `effective_date`, `created_at`, `created_by`, `notes`) VALUES
(1, 1, 0.00, 0.00, '2025-10-28', '2025-10-28 20:59:20', NULL, 'Tarifa inicial del sistema'),
(2, 2, 0.00, 0.00, '2025-10-28', '2025-10-28 20:59:20', NULL, 'Tarifa inicial del sistema'),
(3, 3, 120.00, 6720.00, '2025-10-28', '2025-10-28 20:59:20', NULL, 'Tarifa inicial del sistema');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `hr_notifications`
--

CREATE TABLE `hr_notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `notification_type` varchar(50) NOT NULL COMMENT 'BIRTHDAY, TRIAL_END, VACATION, PERMISSION, DOCUMENT_EXPIRY',
  `employee_id` int(10) UNSIGNED DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `notification_date` date NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `priority` varchar(20) DEFAULT 'NORMAL' COMMENT 'LOW, NORMAL, HIGH, URGENT',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `payroll_deduction_config`
--

CREATE TABLE `payroll_deduction_config` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL COMMENT 'AFP, SFS, ISR, etc',
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `type` enum('PERCENTAGE','FIXED','PROGRESSIVE') NOT NULL DEFAULT 'PERCENTAGE',
  `employee_percentage` decimal(5,2) DEFAULT 0.00 COMMENT 'Porcentaje empleado',
  `employer_percentage` decimal(5,2) DEFAULT 0.00 COMMENT 'Porcentaje empleador',
  `fixed_amount` decimal(10,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `is_mandatory` tinyint(1) DEFAULT 1 COMMENT 'Si es obligatorio por ley',
  `applies_to_salary` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `payroll_deduction_config`
--

INSERT INTO `payroll_deduction_config` (`id`, `code`, `name`, `description`, `type`, `employee_percentage`, `employer_percentage`, `fixed_amount`, `is_active`, `is_mandatory`, `applies_to_salary`, `created_at`, `updated_at`) VALUES
(1, 'AFP', 'Administradora de Fondos de Pensiones', 'Aporte obligatorio al sistema de pensiones', 'PERCENTAGE', 2.87, 7.10, 0.00, 1, 1, 1, '2025-10-30 01:46:02', NULL),
(2, 'SFS', 'Seguro Familiar de Salud', 'Aporte obligatorio al seguro de salud', 'PERCENTAGE', 3.04, 7.09, 0.00, 1, 1, 1, '2025-10-30 01:46:02', NULL),
(3, 'SRL', 'Seguro de Riesgos Laborales', 'Seguro de riesgos laborales (solo empleador)', 'PERCENTAGE', 0.00, 1.20, 0.00, 1, 1, 1, '2025-10-30 01:46:02', NULL),
(4, 'INFOTEP', 'Instituto de Formación Técnico Profesional', 'Aporte para capacitación técnica', 'PERCENTAGE', 0.00, 1.00, 0.00, 1, 1, 1, '2025-10-30 01:46:02', NULL),
(5, 'ISR', 'Impuesto Sobre la Renta', 'Retención de ISR según escala progresiva', 'PROGRESSIVE', 0.00, 0.00, 0.00, 1, 1, 1, '2025-10-30 01:46:02', NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `payroll_isr_scales`
--

CREATE TABLE `payroll_isr_scales` (
  `id` int(11) NOT NULL,
  `min_amount` decimal(12,2) NOT NULL,
  `max_amount` decimal(12,2) DEFAULT NULL COMMENT 'NULL = sin límite superior',
  `base_tax` decimal(12,2) NOT NULL DEFAULT 0.00,
  `excess_rate` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Porcentaje sobre excedente',
  `year` int(11) NOT NULL DEFAULT 2025,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `payroll_isr_scales`
--

INSERT INTO `payroll_isr_scales` (`id`, `min_amount`, `max_amount`, `base_tax`, `excess_rate`, `year`, `is_active`, `created_at`) VALUES
(9, 0.00, 416220.00, 0.00, 0.00, 2025, 1, '2025-10-30 01:51:16'),
(10, 416220.01, 624329.00, 0.00, 15.00, 2025, 1, '2025-10-30 01:51:16'),
(11, 624329.01, 867123.00, 31216.00, 20.00, 2025, 1, '2025-10-30 01:51:16'),
(12, 867123.01, NULL, 79775.00, 25.00, 2025, 1, '2025-10-30 01:51:16');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `payroll_periods`
--

CREATE TABLE `payroll_periods` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `period_type` enum('WEEKLY','BIWEEKLY','MONTHLY','CUSTOM') NOT NULL DEFAULT 'BIWEEKLY',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `payment_date` date NOT NULL,
  `status` enum('DRAFT','CALCULATED','APPROVED','PAID','CLOSED') NOT NULL DEFAULT 'DRAFT',
  `total_gross` decimal(12,2) DEFAULT 0.00,
  `total_deductions` decimal(12,2) DEFAULT 0.00,
  `total_net` decimal(12,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `payroll_periods`
--

INSERT INTO `payroll_periods` (`id`, `name`, `period_type`, `start_date`, `end_date`, `payment_date`, `status`, `total_gross`, `total_deductions`, `total_net`, `notes`, `created_by`, `approved_by`, `approved_at`, `created_at`, `updated_at`) VALUES
(1, 'QUINCENA 1 - 15 DE NOVIEMBRE', 'BIWEEKLY', '2025-10-30', '2025-11-14', '2025-11-15', 'CALCULATED', 0.00, 0.00, 0.00, NULL, 1, NULL, NULL, '2025-10-30 01:54:22', '2025-10-30 01:54:25');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `payroll_records`
--

CREATE TABLE `payroll_records` (
  `id` int(11) NOT NULL,
  `payroll_period_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `base_salary` decimal(10,2) NOT NULL DEFAULT 0.00,
  `overtime_hours` decimal(6,2) DEFAULT 0.00,
  `overtime_amount` decimal(10,2) DEFAULT 0.00,
  `bonuses` decimal(10,2) DEFAULT 0.00,
  `commissions` decimal(10,2) DEFAULT 0.00,
  `other_income` decimal(10,2) DEFAULT 0.00,
  `gross_salary` decimal(10,2) NOT NULL DEFAULT 0.00,
  `afp_employee` decimal(10,2) DEFAULT 0.00,
  `sfs_employee` decimal(10,2) DEFAULT 0.00,
  `isr` decimal(10,2) DEFAULT 0.00,
  `other_deductions` decimal(10,2) DEFAULT 0.00,
  `total_deductions` decimal(10,2) DEFAULT 0.00,
  `afp_employer` decimal(10,2) DEFAULT 0.00,
  `sfs_employer` decimal(10,2) DEFAULT 0.00,
  `srl_employer` decimal(10,2) DEFAULT 0.00,
  `infotep_employer` decimal(10,2) DEFAULT 0.00,
  `total_employer_contributions` decimal(10,2) DEFAULT 0.00,
  `net_salary` decimal(10,2) NOT NULL DEFAULT 0.00,
  `regular_hours` decimal(6,2) DEFAULT 0.00,
  `total_hours` decimal(6,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `is_paid` tinyint(1) DEFAULT 0,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `payroll_records`
--

INSERT INTO `payroll_records` (`id`, `payroll_period_id`, `employee_id`, `base_salary`, `overtime_hours`, `overtime_amount`, `bonuses`, `commissions`, `other_income`, `gross_salary`, `afp_employee`, `sfs_employee`, `isr`, `other_deductions`, `total_deductions`, `afp_employer`, `sfs_employer`, `srl_employer`, `infotep_employer`, `total_employer_contributions`, `net_salary`, `regular_hours`, `total_hours`, `notes`, `is_paid`, `paid_at`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, NULL, 0, NULL, '2025-10-30 01:54:25', NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `permission_requests`
--

CREATE TABLE `permission_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `request_type` varchar(50) NOT NULL COMMENT 'PERMISSION, SICK_LEAVE, PERSONAL, MEDICAL, OTHER',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `total_days` decimal(5,2) NOT NULL DEFAULT 1.00,
  `total_hours` decimal(5,2) DEFAULT NULL,
  `reason` text NOT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING, APPROVED, REJECTED, CANCELLED',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `permission_requests`
--

INSERT INTO `permission_requests` (`id`, `employee_id`, `user_id`, `request_type`, `start_date`, `end_date`, `start_time`, `end_time`, `total_days`, `total_hours`, `reason`, `status`, `reviewed_by`, `reviewed_at`, `review_notes`, `attachment`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'PERMISSION', '2025-10-29', '2025-10-29', '16:14:00', '17:15:00', 1.00, 1.02, 'cita medica', 'PENDING', NULL, NULL, NULL, NULL, '2025-10-29 20:19:22', NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `roles`
--

CREATE TABLE `roles` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `label` varchar(150) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `roles`
--

INSERT INTO `roles` (`id`, `name`, `label`, `description`, `created_at`, `updated_at`) VALUES
(1, 'Admin', 'Administrator', NULL, '2025-10-28 20:34:36', NULL),
(2, 'OperationsManager', 'Operations Manager', NULL, '2025-10-28 20:34:36', NULL),
(3, 'IT', 'IT', NULL, '2025-10-28 20:34:36', NULL),
(4, 'HR', 'Human Resources', NULL, '2025-10-28 20:34:36', NULL),
(5, 'GeneralManager', 'General Manager', NULL, '2025-10-28 20:34:36', NULL),
(6, 'Supervisor', 'Supervisor', NULL, '2025-10-28 20:34:36', NULL),
(7, 'QA', 'Quality Assurance', NULL, '2025-10-28 20:34:36', NULL),
(8, 'AGENT', 'Agent', NULL, '2025-10-28 20:34:36', NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `salary_history`
--

CREATE TABLE `salary_history` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `old_salary` decimal(10,2) NOT NULL,
  `new_salary` decimal(10,2) NOT NULL,
  `change_type` enum('INCREASE','DECREASE','ADJUSTMENT','PROMOTION') NOT NULL,
  `effective_date` date NOT NULL,
  `reason` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `schedule_config`
--

CREATE TABLE `schedule_config` (
  `id` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `entry_time` time NOT NULL DEFAULT '10:00:00',
  `exit_time` time NOT NULL DEFAULT '19:00:00',
  `lunch_time` time NOT NULL DEFAULT '14:00:00',
  `break_time` time NOT NULL DEFAULT '17:00:00',
  `lunch_minutes` int(11) NOT NULL DEFAULT 45,
  `break_minutes` int(11) NOT NULL DEFAULT 15,
  `meeting_minutes` int(11) NOT NULL DEFAULT 45,
  `scheduled_hours` decimal(5,2) NOT NULL DEFAULT 8.00,
  `overtime_enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Activar calculo de horas extras',
  `overtime_multiplier` decimal(4,2) NOT NULL DEFAULT 1.50 COMMENT 'Multiplicador para pago de horas extras (ej: 1.5 = tiempo y medio)',
  `overtime_start_minutes` int(11) NOT NULL DEFAULT 0 COMMENT 'Minutos despues de la hora de salida para comenzar a contar horas extras',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `schedule_config`
--

INSERT INTO `schedule_config` (`id`, `entry_time`, `exit_time`, `lunch_time`, `break_time`, `lunch_minutes`, `break_minutes`, `meeting_minutes`, `scheduled_hours`, `overtime_enabled`, `overtime_multiplier`, `overtime_start_minutes`, `updated_at`) VALUES
(1, '10:00:00', '19:00:00', '14:00:00', '17:00:00', 45, 15, 45, 8.00, 1, 1.50, 0, '2025-10-28 20:34:36');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `section_permissions`
--

CREATE TABLE `section_permissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `section_key` varchar(100) NOT NULL,
  `role` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `section_permissions`
--

INSERT INTO `section_permissions` (`id`, `section_key`, `role`) VALUES
(288, 'adherence_report', 'Admin'),
(289, 'adherence_report', 'HR'),
(290, 'adherence_report', 'IT'),
(309, 'agent_dashboard', 'Admin'),
(310, 'agent_dashboard', 'AGENT'),
(311, 'agent_dashboard', 'IT'),
(312, 'agent_dashboard', 'Supervisor'),
(313, 'agent_records', 'Admin'),
(314, 'agent_records', 'AGENT'),
(315, 'agent_records', 'IT'),
(316, 'agent_records', 'Supervisor'),
(264, 'dashboard', 'Admin'),
(265, 'dashboard', 'AGENT'),
(266, 'dashboard', 'GeneralManager'),
(267, 'dashboard', 'HR'),
(268, 'dashboard', 'IT'),
(269, 'dashboard', 'OperationsManager'),
(270, 'dashboard', 'QA'),
(271, 'dashboard', 'Supervisor'),
(300, 'download_excel', 'Admin'),
(301, 'download_excel', 'HR'),
(302, 'download_excel', 'IT'),
(303, 'download_excel_daily', 'Admin'),
(304, 'download_excel_daily', 'HR'),
(305, 'download_excel_daily', 'IT'),
(329, 'hr_birthdays', 'Admin'),
(330, 'hr_birthdays', 'HR'),
(331, 'hr_birthdays', 'IT'),
(338, 'hr_calendar', 'Admin'),
(339, 'hr_calendar', 'HR'),
(340, 'hr_calendar', 'IT'),
(317, 'hr_dashboard', 'Admin'),
(318, 'hr_dashboard', 'HR'),
(319, 'hr_dashboard', 'IT'),
(341, 'hr_employee_profile', 'Admin'),
(342, 'hr_employee_profile', 'HR'),
(343, 'hr_employee_profile', 'IT'),
(320, 'hr_employees', 'Admin'),
(321, 'hr_employees', 'HR'),
(322, 'hr_employees', 'IT'),
(326, 'hr_payroll', 'Admin'),
(327, 'hr_payroll', 'HR'),
(328, 'hr_payroll', 'IT'),
(332, 'hr_permissions', 'Admin'),
(333, 'hr_permissions', 'HR'),
(334, 'hr_permissions', 'IT'),
(285, 'hr_report', 'Admin'),
(286, 'hr_report', 'HR'),
(287, 'hr_report', 'IT'),
(323, 'hr_trial_period', 'Admin'),
(324, 'hr_trial_period', 'HR'),
(325, 'hr_trial_period', 'IT'),
(335, 'hr_vacations', 'Admin'),
(336, 'hr_vacations', 'HR'),
(337, 'hr_vacations', 'IT'),
(298, 'login_logs', 'Admin'),
(299, 'login_logs', 'IT'),
(291, 'operations_dashboard', 'Admin'),
(292, 'operations_dashboard', 'HR'),
(293, 'operations_dashboard', 'IT'),
(294, 'operations_dashboard', 'OperationsManager'),
(295, 'operations_dashboard', 'Supervisor'),
(272, 'records', 'Admin'),
(273, 'records', 'GeneralManager'),
(274, 'records', 'HR'),
(275, 'records', 'IT'),
(276, 'records', 'OperationsManager'),
(277, 'records', 'Supervisor'),
(278, 'records_qa', 'Admin'),
(279, 'records_qa', 'IT'),
(280, 'records_qa', 'QA'),
(281, 'records_qa', 'Supervisor'),
(296, 'register_attendance', 'Admin'),
(297, 'register_attendance', 'IT'),
(306, 'settings', 'Admin'),
(307, 'settings', 'IT'),
(308, 'settings', 'Supervisor'),
(282, 'view_admin_hours', 'Admin'),
(283, 'view_admin_hours', 'HR'),
(284, 'view_admin_hours', 'IT');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(50) NOT NULL,
  `employee_code` varchar(20) DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'Passwords are stored as plain text by the current application.',
  `role` varchar(50) NOT NULL DEFAULT 'AGENT',
  `hourly_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `monthly_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `hourly_rate_dop` decimal(12,2) NOT NULL DEFAULT 0.00,
  `monthly_salary_dop` decimal(14,2) NOT NULL DEFAULT 0.00,
  `preferred_currency` varchar(3) NOT NULL DEFAULT 'USD',
  `department_id` int(10) UNSIGNED DEFAULT NULL,
  `exit_time` time DEFAULT NULL COMMENT 'Hora de salida personalizada para este empleado',
  `overtime_multiplier` decimal(4,2) DEFAULT NULL COMMENT 'Multiplicador personalizado de horas extras (NULL = usar configuracion global)',
  `reset_token` varchar(64) DEFAULT NULL,
  `token_expiry` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `users`
--

INSERT INTO `users` (`id`, `username`, `employee_code`, `full_name`, `password`, `role`, `hourly_rate`, `monthly_salary`, `hourly_rate_dop`, `monthly_salary_dop`, `preferred_currency`, `department_id`, `exit_time`, `overtime_multiplier`, `reset_token`, `token_expiry`, `created_at`) VALUES
(1, 'admin', 'EMP-2025-0001', 'System Administrator', 'admin123', 'Admin', 35.00, 0.00, 0.00, 0.00, 'USD', 1, NULL, NULL, NULL, NULL, '2025-10-28 20:34:36'),
(2, 'itmanager', 'EMP-2025-0002', 'IT Manager', 'password123', 'IT', 0.00, 0.00, 0.00, 0.00, 'USD', 3, NULL, NULL, NULL, NULL, '2025-10-28 20:34:36'),
(3, 'agentdemo', 'EMP-2025-0003', 'Demo Agent', 'defaultpassword', 'AGENT', 120.00, 19200.00, 6720.00, 1075200.00, 'USD', 1, NULL, NULL, NULL, NULL, '2025-10-28 20:34:36');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `vacation_balances`
--

CREATE TABLE `vacation_balances` (
  `id` int(10) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `year` int(11) NOT NULL,
  `total_days` decimal(5,2) NOT NULL DEFAULT 14.00,
  `used_days` decimal(5,2) NOT NULL DEFAULT 0.00,
  `remaining_days` decimal(5,2) NOT NULL DEFAULT 14.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `vacation_requests`
--

CREATE TABLE `vacation_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `total_days` decimal(5,2) NOT NULL,
  `vacation_type` varchar(50) DEFAULT 'ANNUAL' COMMENT 'ANNUAL, UNPAID, COMPENSATORY',
  `reason` text DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING, APPROVED, REJECTED, CANCELLED',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `administrative_hours`
--
ALTER TABLE `administrative_hours`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_admin_hours_user_timestamp` (`user_id`,`timestamp`),
  ADD KEY `idx_admin_hours_type` (`type`);

--
-- Indices de la tabla `admin_login_logs`
--
ALTER TABLE `admin_login_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_admin_login_logs_user_time` (`user_id`,`login_time`);

--
-- Indices de la tabla `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_attendance_user_timestamp` (`user_id`,`timestamp`),
  ADD KEY `idx_attendance_type` (`type`);

--
-- Indices de la tabla `attendance_types`
--
ALTER TABLE `attendance_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indices de la tabla `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `departments_name_unique` (`name`);

--
-- Indices de la tabla `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employees_user_id_unique` (`user_id`),
  ADD UNIQUE KEY `employees_employee_code_unique` (`employee_code`),
  ADD UNIQUE KEY `employees_email_unique` (`email`),
  ADD KEY `idx_employees_hire_date` (`hire_date`),
  ADD KEY `idx_employees_birth_date` (`birth_date`),
  ADD KEY `idx_employees_status` (`employment_status`),
  ADD KEY `idx_employees_department` (`department_id`),
  ADD KEY `idx_employees_supervisor` (`supervisor_id`);

--
-- Indices de la tabla `employee_deductions`
--
ALTER TABLE `employee_deductions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_employee_id` (`employee_id`),
  ADD KEY `idx_deduction_config_id` (`deduction_config_id`),
  ADD KEY `idx_employee_deductions_active` (`is_active`);

--
-- Indices de la tabla `employee_documents`
--
ALTER TABLE `employee_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_employee_documents_employee` (`employee_id`),
  ADD KEY `idx_employee_documents_type` (`document_type`),
  ADD KEY `fk_employee_documents_uploader` (`uploaded_by`);

--
-- Indices de la tabla `hourly_rate_history`
--
ALTER TABLE `hourly_rate_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_effective_date` (`user_id`,`effective_date`),
  ADD KEY `idx_effective_date` (`effective_date`),
  ADD KEY `idx_user_date_lookup` (`user_id`,`effective_date`,`id`);

--
-- Indices de la tabla `hr_notifications`
--
ALTER TABLE `hr_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hr_notifications_date` (`notification_date`),
  ADD KEY `idx_hr_notifications_type` (`notification_type`),
  ADD KEY `idx_hr_notifications_employee` (`employee_id`);

--
-- Indices de la tabla `payroll_deduction_config`
--
ALTER TABLE `payroll_deduction_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indices de la tabla `payroll_isr_scales`
--
ALTER TABLE `payroll_isr_scales`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `payroll_periods`
--
ALTER TABLE `payroll_periods`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_approved_by` (`approved_by`),
  ADD KEY `idx_payroll_period_dates` (`start_date`,`end_date`),
  ADD KEY `idx_payroll_period_status` (`status`);

--
-- Indices de la tabla `payroll_records`
--
ALTER TABLE `payroll_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_employee_period` (`payroll_period_id`,`employee_id`),
  ADD KEY `idx_payroll_period_id` (`payroll_period_id`),
  ADD KEY `idx_employee_id` (`employee_id`);

--
-- Indices de la tabla `permission_requests`
--
ALTER TABLE `permission_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_permission_requests_employee` (`employee_id`),
  ADD KEY `idx_permission_requests_dates` (`start_date`,`end_date`),
  ADD KEY `idx_permission_requests_status` (`status`),
  ADD KEY `fk_permission_requests_user` (`user_id`),
  ADD KEY `fk_permission_requests_reviewer` (`reviewed_by`);

--
-- Indices de la tabla `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `roles_name_unique` (`name`);

--
-- Indices de la tabla `salary_history`
--
ALTER TABLE `salary_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_employee_id` (`employee_id`),
  ADD KEY `idx_approved_by` (`approved_by`);

--
-- Indices de la tabla `schedule_config`
--
ALTER TABLE `schedule_config`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `section_permissions`
--
ALTER TABLE `section_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `section_role_unique` (`section_key`,`role`);

--
-- Indices de la tabla `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_username_unique` (`username`),
  ADD UNIQUE KEY `employee_code` (`employee_code`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_department` (`department_id`),
  ADD KEY `idx_users_employee_code` (`employee_code`);

--
-- Indices de la tabla `vacation_balances`
--
ALTER TABLE `vacation_balances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vacation_balances_employee_year_unique` (`employee_id`,`year`);

--
-- Indices de la tabla `vacation_requests`
--
ALTER TABLE `vacation_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vacation_requests_employee` (`employee_id`),
  ADD KEY `idx_vacation_requests_dates` (`start_date`,`end_date`),
  ADD KEY `idx_vacation_requests_status` (`status`),
  ADD KEY `fk_vacation_requests_user` (`user_id`),
  ADD KEY `fk_vacation_requests_reviewer` (`reviewed_by`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `administrative_hours`
--
ALTER TABLE `administrative_hours`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `admin_login_logs`
--
ALTER TABLE `admin_login_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `attendance_types`
--
ALTER TABLE `attendance_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `employee_deductions`
--
ALTER TABLE `employee_deductions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `employee_documents`
--
ALTER TABLE `employee_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `hourly_rate_history`
--
ALTER TABLE `hourly_rate_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `hr_notifications`
--
ALTER TABLE `hr_notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `payroll_deduction_config`
--
ALTER TABLE `payroll_deduction_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT de la tabla `payroll_isr_scales`
--
ALTER TABLE `payroll_isr_scales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT de la tabla `payroll_periods`
--
ALTER TABLE `payroll_periods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `payroll_records`
--
ALTER TABLE `payroll_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `permission_requests`
--
ALTER TABLE `permission_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT de la tabla `salary_history`
--
ALTER TABLE `salary_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `section_permissions`
--
ALTER TABLE `section_permissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=344;

--
-- AUTO_INCREMENT de la tabla `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `vacation_balances`
--
ALTER TABLE `vacation_balances`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `vacation_requests`
--
ALTER TABLE `vacation_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `administrative_hours`
--
ALTER TABLE `administrative_hours`
  ADD CONSTRAINT `fk_admin_hours_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `admin_login_logs`
--
ALTER TABLE `admin_login_logs`
  ADD CONSTRAINT `fk_admin_login_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `fk_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `fk_employees_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_employees_supervisor` FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_employees_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `employee_documents`
--
ALTER TABLE `employee_documents`
  ADD CONSTRAINT `fk_employee_documents_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_employee_documents_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Filtros para la tabla `hourly_rate_history`
--
ALTER TABLE `hourly_rate_history`
  ADD CONSTRAINT `fk_rate_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `hr_notifications`
--
ALTER TABLE `hr_notifications`
  ADD CONSTRAINT `fk_hr_notifications_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `permission_requests`
--
ALTER TABLE `permission_requests`
  ADD CONSTRAINT `fk_permission_requests_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_permission_requests_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_permission_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Filtros para la tabla `vacation_balances`
--
ALTER TABLE `vacation_balances`
  ADD CONSTRAINT `fk_vacation_balances_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `vacation_requests`
--
ALTER TABLE `vacation_requests`
  ADD CONSTRAINT `fk_vacation_requests_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_vacation_requests_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_vacation_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;