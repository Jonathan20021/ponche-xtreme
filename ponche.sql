-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost:3306
-- Tiempo de generación: 03-11-2025 a las 20:33:38
-- Versión del servidor: 5.7.23-23
-- Versión de PHP: 8.1.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `hhempeos_ponche`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_role` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `module` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `old_values` text COLLATE utf8mb4_unicode_ci,
  `new_values` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `user_name`, `user_role`, `module`, `action`, `description`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'System Administrator', 'Admin', 'attendance', 'update', 'Registro de asistencia modificado para Jonathan Sandoval (ID: 9)', 'attendance_record', 9, '{\"type\":\"PAUSA\",\"timestamp\":\"2025-11-03 18:34:00\",\"ip_address\":\"127.0.0.1\"}', '{\"type\":\"WASAPI\",\"timestamp\":\"2025-11-03T18:34\",\"ip_address\":\"127.0.0.1\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-04 01:02:49'),
(2, 1, 'System Administrator', 'Admin', 'system_settings', 'update', 'Configuración del sistema actualizada: exchange_rate_usd_to_dop - Tasa de cambio: 58.50 → 64.25 DOP', 'system_setting', NULL, '{\"rate\":\"58.50\"}', '{\"rate\":64.25,\"updated_at\":\"2025-11-04 02:52:17\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-04 01:52:49'),
(3, 1, 'System Administrator', 'Admin', 'document_uploaded', 'employee_documents', '1', 'Documento subido para Jonathan Sandoval: Cédula - pngtree-student-id-card-business-card-template-bea', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-04 02:13:58');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `administrative_hours`
--

CREATE TABLE `administrative_hours` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `administrative_hours`
--

INSERT INTO `administrative_hours` (`id`, `user_id`, `type`, `timestamp`, `ip_address`) VALUES
(1, 1, 'Entry', '2025-10-30 10:52:53', '148.101.215.190');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `admin_login_logs`
--

CREATE TABLE `admin_login_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `login_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `admin_login_logs`
--

INSERT INTO `admin_login_logs` (`id`, `user_id`, `username`, `role`, `ip_address`, `public_ip`, `location`, `login_time`) VALUES
(1, 1, 'admin', 'Admin', '127.0.0.1', '170.80.202.31', 'Santiago de los Caballeros, Santiago Province, DO', '2025-10-28 17:13:22'),
(2, 1, 'admin', 'Admin', '170.80.202.31', '192.185.4.127', 'Atlanta, Georgia, US', '2025-10-29 21:54:13'),
(3, 1, 'admin', 'Admin', '170.80.202.31', '192.185.4.127', 'Atlanta, Georgia, US', '2025-10-29 21:55:06'),
(4, 1, 'admin', 'Admin', '170.80.202.31', '192.185.4.127', 'Atlanta, Georgia, US', '2025-10-29 22:00:27'),
(5, 1, 'admin', 'Admin', '190.167.91.199', '192.185.4.127', 'Atlanta, Georgia, US', '2025-10-29 22:03:09'),
(6, 1, 'admin', 'Admin', '148.101.215.190', '192.185.4.127', 'Atlanta, Georgia, US', '2025-10-29 22:08:08'),
(7, 1, 'admin', 'Admin', '127.0.0.1', '170.80.202.31', 'Santiago de los Caballeros, Santiago Province, DO', '2025-10-29 22:21:57'),
(8, 1, 'admin', 'Admin', '127.0.0.1', '170.80.202.31', 'Santiago de los Caballeros, Santiago Province, DO', '2025-10-30 07:37:31'),
(9, 1, 'admin', 'Admin', '190.52.238.100', '192.185.4.127', 'Atlanta, Georgia, US', '2025-10-30 10:59:31'),
(10, 1, 'admin', 'Admin', '190.52.238.100', '192.185.4.127', 'Atlanta, Georgia, US', '2025-10-30 12:40:33'),
(11, 1, 'admin', 'Admin', '154.43.45.58', '192.185.4.127', 'Atlanta, Georgia, US', '2025-10-30 16:32:10'),
(12, 1, 'admin', 'Admin', '154.43.45.58', '192.185.4.127', 'Atlanta, Georgia, US', '2025-10-30 16:53:45'),
(13, 1, 'admin', 'Admin', '148.101.215.190', '192.185.4.127', 'Atlanta, Georgia, US', '2025-10-30 21:19:36'),
(14, 1, 'admin', 'Admin', '148.101.215.190', '192.185.4.127', 'Atlanta, Georgia, US', '2025-10-30 21:43:37'),
(15, 1, 'admin', 'Admin', '148.101.215.190', '192.185.4.127', 'Atlanta, Georgia, US', '2025-10-30 21:53:19'),
(16, 1, 'admin', 'Admin', '154.43.45.58', '192.185.4.127', 'Atlanta, Georgia, US', '2025-10-31 16:21:35'),
(17, 1, 'admin', 'Admin', '154.43.45.58', '192.185.4.127', 'Atlanta, Georgia, US', '2025-10-31 17:37:31'),
(18, 1, 'admin', 'Admin', '170.80.202.31', '192.185.4.127', 'Atlanta, Georgia, US', '2025-10-31 17:44:46'),
(19, 1, 'admin', 'Admin', '190.167.91.199', '192.185.4.127', 'Atlanta, Georgia, US', '2025-10-31 23:08:36'),
(20, 1, 'admin', 'Admin', '127.0.0.1', '170.80.202.31', 'Santiago de los Caballeros, Santiago Province, DO', '2025-11-03 01:39:14'),
(21, 1, 'admin', 'Admin', '170.80.202.31', '192.185.4.127', 'Atlanta, Georgia, US', '2025-11-03 02:03:14'),
(22, 1, 'admin', 'Admin', '154.43.45.58', '192.185.4.127', 'Atlanta, Georgia, US', '2025-11-03 07:20:52'),
(23, 1, 'admin', 'Admin', '170.80.202.31', '192.185.4.127', 'Atlanta, Georgia, US', '2025-11-03 08:02:47'),
(24, 1, 'admin', 'Admin', '154.43.45.58', '192.185.4.127', 'Atlanta, Georgia, US', '2025-11-03 08:44:05'),
(25, 1, 'admin', 'Admin', '190.52.238.100', '192.185.4.127', 'Atlanta, Georgia, US', '2025-11-03 10:07:35'),
(26, 1, 'admin', 'Admin', '154.43.45.58', '192.185.4.127', 'Atlanta, Georgia, US', '2025-11-03 10:16:22'),
(27, 5, 'Ncastillo', 'Supervisor', '154.43.45.58', '192.185.4.127', 'Atlanta, Georgia, US', '2025-11-03 10:46:25'),
(28, 1, 'admin', 'Admin', '190.52.238.100', '192.185.4.127', 'Atlanta, Georgia, US', '2025-11-03 11:21:56'),
(29, 1, 'admin', 'Admin', '154.43.45.58', '192.185.4.127', 'Atlanta, Georgia, US', '2025-11-03 11:38:47'),
(30, 1, 'admin', 'Admin', '190.52.238.100', '192.185.4.127', 'Atlanta, Georgia, US', '2025-11-03 11:51:03'),
(31, 1, 'admin', 'Admin', '154.43.45.58', '192.185.4.127', 'Atlanta, Georgia, US', '2025-11-03 13:05:06'),
(32, 1, 'admin', 'Admin', '190.52.238.100', '192.185.4.127', 'Atlanta, Georgia, US', '2025-11-03 14:36:18'),
(33, 1, 'admin', 'Admin', '170.80.202.31', '192.185.4.127', 'Atlanta, Georgia, US', '2025-11-03 16:16:45'),
(34, 5, 'Ncastillo', 'Supervisor', '154.43.45.58', '192.185.4.127', 'Atlanta, Georgia, US', '2025-11-03 16:26:31'),
(35, 1, 'admin', 'Admin', '170.80.202.31', '192.185.4.127', 'Atlanta, Georgia, US', '2025-11-03 16:35:40'),
(36, 1, 'admin', 'Admin', '170.80.202.31', '192.185.4.127', 'Atlanta, Georgia, US', '2025-11-03 17:55:28'),
(37, 1, 'admin', 'Admin', '170.80.202.31', '192.185.4.127', 'Atlanta, Georgia, US', '2025-11-03 19:12:42'),
(38, 1, 'admin', 'Admin', '170.80.202.31', '192.185.4.127', 'Atlanta, Georgia, US', '2025-11-03 20:17:41');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `applicant_references`
--

CREATE TABLE `applicant_references` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `reference_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_company` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_position` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `relationship` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contacted` tinyint(1) DEFAULT '0',
  `notes` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `applicant_skills`
--

CREATE TABLE `applicant_skills` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `skill_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `proficiency_level` enum('beginner','intermediate','advanced','expert') COLLATE utf8mb4_unicode_ci DEFAULT 'intermediate',
  `years_experience` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `application_comments`
--

CREATE TABLE `application_comments` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `comment` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_internal` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `application_status_history`
--

CREATE TABLE `application_status_history` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `old_status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `changed_by` int(10) UNSIGNED DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `changed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `attendance`
--

CREATE TABLE `attendance` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
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
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `is_unique_daily` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Volcado de datos para la tabla `attendance_types`
--

INSERT INTO `attendance_types` (`id`, `slug`, `label`, `icon_class`, `shortcut_key`, `color_start`, `color_end`, `sort_order`, `is_unique_daily`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'ENTRY', 'Entry', 'fas fa-sign-in-alt', 'E', '#22C55E', '#16A34A', 10, 1, 1, '2025-10-28 15:07:21', '2025-11-04 00:35:46'),
(2, 'BREAK', 'Break', 'fas fa-coffee', 'B', '#3B82F6', '#2563EB', 20, 0, 1, '2025-10-28 15:07:21', '2025-11-04 00:35:46'),
(3, 'PAUSA', 'Pausa', 'fas fa-utensils', 'L', '#EAB308', '#CA8A04', 30, 0, 1, '2025-10-28 15:07:21', '2025-11-04 00:35:46'),
(4, 'WASAPI', 'Wasapi', 'fas fa-users', 'M', '#A855F7', '#7C3AED', 40, 0, 1, '2025-10-28 15:07:21', '2025-11-04 00:35:46'),
(5, 'DIGITACION', 'Digitacion', 'fas fa-tasks', 'F', '#6366F1', '#4338CA', 50, 0, 1, '2025-10-28 15:07:21', '2025-11-04 00:35:46'),
(6, 'BA_NO', 'Baño', 'fas fa-check', 'R', '#8B5CF6', '#6D28D9', 60, 0, 1, '2025-10-28 15:07:21', '2025-11-04 00:35:46'),
(7, 'EXIT', 'Exit', 'fas fa-sign-out-alt', 'X', '#EF4444', '#DC2626', 70, 1, 1, '2025-10-28 15:07:21', '2025-11-04 00:35:46');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `banks`
--

CREATE TABLE `banks` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Bank code or abbreviation',
  `swift_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'República Dominicana',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `banks`
--

INSERT INTO `banks` (`id`, `name`, `code`, `swift_code`, `country`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Banco Popular Dominicano', 'BPD', NULL, 'República Dominicana', 1, '2025-10-31 21:17:48', NULL),
(2, 'Banco de Reservas', 'BANRESERVAS', NULL, 'República Dominicana', 1, '2025-10-31 21:17:48', NULL),
(3, 'Banco BHD León', 'BHD', NULL, 'República Dominicana', 1, '2025-10-31 21:17:48', NULL),
(4, 'Banco Santa Cruz', 'BSC', NULL, 'República Dominicana', 1, '2025-10-31 21:17:48', NULL),
(5, 'Banco López de Haro', 'BLH', NULL, 'República Dominicana', 1, '2025-10-31 21:17:48', NULL),
(6, 'Scotiabank', 'SCOTIA', NULL, 'República Dominicana', 1, '2025-10-31 21:17:48', NULL),
(7, 'Citibank', 'CITI', NULL, 'República Dominicana', 1, '2025-10-31 21:17:48', NULL),
(8, 'Banco Promerica', 'PROMERICA', NULL, 'República Dominicana', 1, '2025-10-31 21:17:48', NULL),
(9, 'Banco Caribe', 'CARIBE', NULL, 'República Dominicana', 1, '2025-10-31 21:17:48', NULL),
(10, 'Banco Múltiple Ademi', 'ADEMI', NULL, 'República Dominicana', 1, '2025-10-31 21:17:48', NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `calendar_events`
--

CREATE TABLE `calendar_events` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `event_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `event_type` enum('MEETING','REMINDER','DEADLINE','HOLIDAY','TRAINING','OTHER') COLLATE utf8mb4_unicode_ci DEFAULT 'OTHER',
  `color` varchar(7) COLLATE utf8mb4_unicode_ci DEFAULT '#6366f1',
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_all_day` tinyint(1) DEFAULT '0',
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `calendar_events`
--

INSERT INTO `calendar_events` (`id`, `title`, `description`, `event_date`, `start_time`, `end_time`, `event_type`, `color`, `location`, `is_all_day`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'Día de la Independencia', 'Feriado nacional', '2025-02-27', NULL, NULL, 'HOLIDAY', '#10b981', NULL, 1, 1, '2025-11-03 17:34:54', '2025-11-03 17:34:54');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `calendar_event_attendees`
--

CREATE TABLE `calendar_event_attendees` (
  `id` int(10) UNSIGNED NOT NULL,
  `event_id` int(10) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `status` enum('PENDING','ACCEPTED','DECLINED','TENTATIVE') COLLATE utf8mb4_unicode_ci DEFAULT 'PENDING',
  `notified` tinyint(1) DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `calendar_event_reminders`
--

CREATE TABLE `calendar_event_reminders` (
  `id` int(10) UNSIGNED NOT NULL,
  `event_id` int(10) UNSIGNED NOT NULL,
  `reminder_minutes` int(11) NOT NULL COMMENT 'Minutes before event to remind',
  `reminder_sent` tinyint(1) DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `departments`
--

CREATE TABLE `departments` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `departments`
--

INSERT INTO `departments` (`id`, `name`, `description`, `created_at`, `updated_at`) VALUES
(1, 'Operations', 'Operations and service delivery', '2025-10-28 20:34:36', '2025-10-31 21:36:20'),
(2, 'Human Resources', 'People and talent management', '2025-10-28 20:34:36', '2025-10-31 21:36:20'),
(3, 'Technology', 'IT and systems team', '2025-10-28 20:34:36', '2025-10-31 21:36:20'),
(4, 'Quality Assurance', 'QA and compliance', '2025-10-28 20:34:36', '2025-10-31 21:36:20'),
(5, 'Presidente & CEO', 'Client success and account management', '2025-10-28 20:34:36', '2025-10-31 21:36:20');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `employees`
--

CREATE TABLE `employees` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `employee_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `hire_date` date NOT NULL,
  `termination_date` date DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'República Dominicana',
  `emergency_contact_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_contact_phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_contact_relationship` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identification_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Cédula, Pasaporte, etc.',
  `identification_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_card_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Número de cédula de identidad',
  `bank_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Bank where employee has account',
  `bank_account_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Employee bank account number',
  `bank_account_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'AHORROS_DOP, AHORROS_USD, CORRIENTE_DOP, CORRIENTE_USD',
  `blood_type` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marital_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gender` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `position` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department_id` int(10) UNSIGNED DEFAULT NULL,
  `supervisor_id` int(10) UNSIGNED DEFAULT NULL,
  `employment_status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ACTIVE' COMMENT 'ACTIVE, TRIAL, SUSPENDED, TERMINATED',
  `employment_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'FULL_TIME' COMMENT 'FULL_TIME, PART_TIME, CONTRACT, INTERN',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `profile_photo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Path to employee photo',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `employees`
--

INSERT INTO `employees` (`id`, `user_id`, `employee_code`, `first_name`, `last_name`, `email`, `phone`, `mobile`, `birth_date`, `hire_date`, `termination_date`, `address`, `city`, `state`, `postal_code`, `country`, `emergency_contact_name`, `emergency_contact_phone`, `emergency_contact_relationship`, `identification_type`, `identification_number`, `id_card_number`, `bank_id`, `bank_account_number`, `bank_account_type`, `blood_type`, `marital_status`, `gender`, `position`, `department_id`, `supervisor_id`, `employment_status`, `employment_type`, `notes`, `profile_photo`, `photo_path`, `created_at`, `updated_at`) VALUES
(1, 1, 'EMP-2025-0001', 'System', 'Administrator', NULL, NULL, NULL, '2002-02-15', '2025-10-28', NULL, NULL, NULL, NULL, NULL, 'República Dominicana', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, 'ACTIVE', 'FULL_TIME', NULL, NULL, NULL, '2025-10-29 20:14:26', '2025-10-29 20:59:41'),
(2, 4, 'EMP-2025-0004', 'Hugo', 'Hidalgo', 'hhidalgo@evallishbpo.com', '8094178954', NULL, '2025-10-31', '2025-10-31', NULL, NULL, NULL, NULL, NULL, 'República Dominicana', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Presidente', 5, NULL, 'TRIAL', 'FULL_TIME', NULL, NULL, NULL, '2025-10-31 22:42:40', NULL),
(3, 5, 'EMP-2025-0005', 'Nitida', 'Ivelisse Castillo De La Rosa', NULL, NULL, NULL, NULL, '2025-11-03', NULL, NULL, NULL, NULL, NULL, 'República Dominicana', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, 'TRIAL', 'FULL_TIME', NULL, NULL, NULL, '2025-11-03 16:39:01', NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `employee_deductions`
--

CREATE TABLE `employee_deductions` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `deduction_config_id` int(11) DEFAULT NULL COMMENT 'NULL si es descuento personalizado',
  `name` varchar(100) NOT NULL,
  `description` text,
  `type` enum('PERCENTAGE','FIXED') NOT NULL DEFAULT 'FIXED',
  `amount` decimal(10,2) NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `employee_documents`
--

CREATE TABLE `employee_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `document_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'CONTRACT, ID, CERTIFICATE, RESUME, etc.',
  `document_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `file_extension` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `uploaded_by` int(10) UNSIGNED DEFAULT NULL,
  `uploaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `employee_health_stats`
--

CREATE TABLE `employee_health_stats` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `year` int(11) NOT NULL,
  `total_medical_leaves` int(11) DEFAULT '0',
  `total_days_on_leave` decimal(6,2) DEFAULT '0.00',
  `total_work_related_incidents` int(11) DEFAULT '0',
  `last_medical_leave_date` date DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Estadísticas de salud por empleado';

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `employee_schedules`
--

CREATE TABLE `employee_schedules` (
  `id` int(10) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `schedule_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nombre del turno (ej: Turno Mañana, Turno Tarde)',
  `entry_time` time NOT NULL DEFAULT '10:00:00' COMMENT 'Hora de entrada',
  `exit_time` time NOT NULL DEFAULT '19:00:00' COMMENT 'Hora de salida',
  `lunch_time` time DEFAULT '14:00:00' COMMENT 'Hora de almuerzo',
  `break_time` time DEFAULT '17:00:00' COMMENT 'Hora de descanso',
  `lunch_minutes` int(11) NOT NULL DEFAULT '45' COMMENT 'Minutos de almuerzo',
  `break_minutes` int(11) NOT NULL DEFAULT '15' COMMENT 'Minutos de descanso',
  `scheduled_hours` decimal(5,2) NOT NULL DEFAULT '8.00' COMMENT 'Horas programadas por día',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Si el horario está activo',
  `effective_date` date DEFAULT NULL COMMENT 'Fecha desde cuando aplica este horario',
  `end_date` date DEFAULT NULL COMMENT 'Fecha hasta cuando aplica (NULL = indefinido)',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `employment_contracts`
--

CREATE TABLE `employment_contracts` (
  `id` int(10) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED DEFAULT NULL,
  `employee_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_card` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `province` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contract_date` date NOT NULL,
  `salary` decimal(10,2) NOT NULL,
  `work_schedule` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '44 horas semanales',
  `city` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Ciudad de Santiago',
  `contract_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'TRABAJO',
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `hourly_rate_history`
--

CREATE TABLE `hourly_rate_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `hourly_rate_usd` decimal(10,2) NOT NULL DEFAULT '0.00',
  `hourly_rate_dop` decimal(12,2) NOT NULL DEFAULT '0.00',
  `effective_date` date NOT NULL COMMENT 'Fecha desde la cual esta tarifa es valida',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'Usuario que registro el cambio',
  `notes` text COLLATE utf8mb4_unicode_ci COMMENT 'Notas sobre el cambio de tarifa'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Historial de cambios de tarifas por hora con fechas efectivas';

--
-- Volcado de datos para la tabla `hourly_rate_history`
--

INSERT INTO `hourly_rate_history` (`id`, `user_id`, `hourly_rate_usd`, `hourly_rate_dop`, `effective_date`, `created_at`, `created_by`, `notes`) VALUES
(1, 1, 0.00, 0.00, '2025-10-28', '2025-10-28 20:59:20', NULL, 'Tarifa inicial del sistema');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `hr_notifications`
--

CREATE TABLE `hr_notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `notification_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'BIRTHDAY, TRIAL_END, VACATION, PERMISSION, DOCUMENT_EXPIRY',
  `employee_id` int(10) UNSIGNED DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `notification_date` date NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `priority` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'NORMAL' COMMENT 'LOW, NORMAL, HIGH, URGENT',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `job_applications`
--

CREATE TABLE `job_applications` (
  `id` int(11) NOT NULL,
  `application_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `job_posting_id` int(11) DEFAULT NULL,
  `first_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `education_level` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `years_of_experience` int(11) DEFAULT NULL,
  `current_position` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `current_company` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expected_salary` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `availability_date` date DEFAULT NULL,
  `cv_filename` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cv_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cover_letter` text COLLATE utf8mb4_unicode_ci,
  `linkedin_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `portfolio_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('new','reviewing','shortlisted','interview_scheduled','interviewed','offer_extended','hired','rejected','withdrawn') COLLATE utf8mb4_unicode_ci DEFAULT 'new',
  `overall_rating` int(11) DEFAULT NULL,
  `applied_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `assigned_to` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `job_postings`
--

CREATE TABLE `job_postings` (
  `id` int(11) NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `department` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `employment_type` enum('full_time','part_time','contract','internship') COLLATE utf8mb4_unicode_ci DEFAULT 'full_time',
  `description` text COLLATE utf8mb4_unicode_ci,
  `requirements` text COLLATE utf8mb4_unicode_ci,
  `responsibilities` text COLLATE utf8mb4_unicode_ci,
  `salary_range` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','inactive','closed') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `posted_date` date NOT NULL,
  `closing_date` date DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `medical_leaves`
--

CREATE TABLE `medical_leaves` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `leave_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'MEDICAL' COMMENT 'MEDICAL, MATERNITY, PATERNITY, ACCIDENT, SURGERY, CHRONIC',
  `diagnosis` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Diagnóstico médico',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `expected_return_date` date DEFAULT NULL,
  `actual_return_date` date DEFAULT NULL,
  `total_days` decimal(5,2) NOT NULL DEFAULT '1.00',
  `is_paid` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Si la licencia es pagada',
  `payment_percentage` decimal(5,2) DEFAULT '100.00' COMMENT 'Porcentaje de pago (ej: 100%, 60%)',
  `doctor_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `medical_center` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `medical_certificate_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `medical_certificate_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Ruta al archivo del certificado médico',
  `prescription_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Ruta a recetas médicas',
  `reason` text COLLATE utf8mb4_unicode_ci COMMENT 'Razón detallada de la licencia',
  `notes` text COLLATE utf8mb4_unicode_ci COMMENT 'Notas adicionales',
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING, APPROVED, REJECTED, CANCELLED, EXTENDED, COMPLETED',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_notes` text COLLATE utf8mb4_unicode_ci,
  `requires_followup` tinyint(1) DEFAULT '0' COMMENT 'Si requiere seguimiento médico',
  `followup_date` date DEFAULT NULL,
  `followup_notes` text COLLATE utf8mb4_unicode_ci,
  `is_work_related` tinyint(1) DEFAULT '0' COMMENT 'Si es relacionado con el trabajo (accidente laboral)',
  `ars_claim_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Número de reclamación ARS',
  `ars_status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Estado de reclamación ARS',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro de licencias médicas y relacionadas';

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `medical_leave_extensions`
--

CREATE TABLE `medical_leave_extensions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `medical_leave_id` bigint(20) UNSIGNED NOT NULL,
  `previous_end_date` date NOT NULL,
  `new_end_date` date NOT NULL,
  `extension_days` int(11) NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `medical_certificate_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requested_by` int(10) UNSIGNED NOT NULL,
  `approved_by` int(10) UNSIGNED DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING, APPROVED, REJECTED',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Extensiones de licencias médicas';

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `medical_leave_followups`
--

CREATE TABLE `medical_leave_followups` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `medical_leave_id` bigint(20) UNSIGNED NOT NULL,
  `followup_date` date NOT NULL,
  `followup_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'CHECKUP' COMMENT 'CHECKUP, TREATMENT, THERAPY, EXAM, OTHER',
  `medical_center` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `doctor_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'SCHEDULED' COMMENT 'SCHEDULED, COMPLETED, CANCELLED, RESCHEDULED',
  `next_followup_date` date DEFAULT NULL,
  `attachment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recorded_by` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Seguimientos de licencias médicas';

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `payroll_deduction_config`
--

CREATE TABLE `payroll_deduction_config` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL COMMENT 'AFP, SFS, ISR, etc',
  `name` varchar(100) NOT NULL,
  `description` text,
  `type` enum('PERCENTAGE','FIXED','PROGRESSIVE') NOT NULL DEFAULT 'PERCENTAGE',
  `employee_percentage` decimal(5,2) DEFAULT '0.00' COMMENT 'Porcentaje empleado',
  `employer_percentage` decimal(5,2) DEFAULT '0.00' COMMENT 'Porcentaje empleador',
  `fixed_amount` decimal(10,2) DEFAULT '0.00',
  `is_active` tinyint(1) DEFAULT '1',
  `is_mandatory` tinyint(1) DEFAULT '1' COMMENT 'Si es obligatorio por ley',
  `applies_to_salary` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Volcado de datos para la tabla `payroll_deduction_config`
--

INSERT INTO `payroll_deduction_config` (`id`, `code`, `name`, `description`, `type`, `employee_percentage`, `employer_percentage`, `fixed_amount`, `is_active`, `is_mandatory`, `applies_to_salary`, `created_at`, `updated_at`) VALUES
(1, 'AFP', 'Administradora de Fondos de Pensiones', 'Aporte obligatorio al sistema de pensiones', 'PERCENTAGE', 2.89, 7.10, 0.00, 1, 1, 1, '2025-10-30 01:46:02', '2025-11-03 23:48:06'),
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
  `base_tax` decimal(12,2) NOT NULL DEFAULT '0.00',
  `excess_rate` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT 'Porcentaje sobre excedente',
  `year` int(11) NOT NULL DEFAULT '2025',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
  `total_gross` decimal(12,2) DEFAULT '0.00',
  `total_deductions` decimal(12,2) DEFAULT '0.00',
  `total_net` decimal(12,2) DEFAULT '0.00',
  `notes` text,
  `created_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `payroll_records`
--

CREATE TABLE `payroll_records` (
  `id` int(11) NOT NULL,
  `payroll_period_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `base_salary` decimal(10,2) NOT NULL DEFAULT '0.00',
  `overtime_hours` decimal(6,2) DEFAULT '0.00',
  `overtime_amount` decimal(10,2) DEFAULT '0.00',
  `bonuses` decimal(10,2) DEFAULT '0.00',
  `commissions` decimal(10,2) DEFAULT '0.00',
  `other_income` decimal(10,2) DEFAULT '0.00',
  `gross_salary` decimal(10,2) NOT NULL DEFAULT '0.00',
  `afp_employee` decimal(10,2) DEFAULT '0.00',
  `sfs_employee` decimal(10,2) DEFAULT '0.00',
  `isr` decimal(10,2) DEFAULT '0.00',
  `other_deductions` decimal(10,2) DEFAULT '0.00',
  `total_deductions` decimal(10,2) DEFAULT '0.00',
  `afp_employer` decimal(10,2) DEFAULT '0.00',
  `sfs_employer` decimal(10,2) DEFAULT '0.00',
  `srl_employer` decimal(10,2) DEFAULT '0.00',
  `infotep_employer` decimal(10,2) DEFAULT '0.00',
  `total_employer_contributions` decimal(10,2) DEFAULT '0.00',
  `net_salary` decimal(10,2) NOT NULL DEFAULT '0.00',
  `regular_hours` decimal(6,2) DEFAULT '0.00',
  `total_hours` decimal(6,2) DEFAULT '0.00',
  `notes` text,
  `is_paid` tinyint(1) DEFAULT '0',
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `permission_requests`
--

CREATE TABLE `permission_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `request_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'PERMISSION, SICK_LEAVE, PERSONAL, MEDICAL, OTHER',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `total_days` decimal(5,2) NOT NULL DEFAULT '1.00',
  `total_hours` decimal(5,2) DEFAULT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING, APPROVED, REJECTED, CANCELLED',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_notes` text COLLATE utf8mb4_unicode_ci,
  `attachment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `recruitment_interviews`
--

CREATE TABLE `recruitment_interviews` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `interview_type` enum('phone_screening','technical','hr','manager','final','other') COLLATE utf8mb4_unicode_ci DEFAULT 'hr',
  `interview_date` datetime NOT NULL,
  `duration_minutes` int(11) DEFAULT '60',
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meeting_link` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `interviewer_ids` text COLLATE utf8mb4_unicode_ci,
  `status` enum('scheduled','completed','cancelled','rescheduled','no_show') COLLATE utf8mb4_unicode_ci DEFAULT 'scheduled',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `feedback` text COLLATE utf8mb4_unicode_ci,
  `rating` int(11) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `roles`
--

CREATE TABLE `roles` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
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
  `reason` text,
  `approved_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `schedule_config`
--

CREATE TABLE `schedule_config` (
  `id` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `entry_time` time NOT NULL DEFAULT '10:00:00',
  `exit_time` time NOT NULL DEFAULT '19:00:00',
  `lunch_time` time NOT NULL DEFAULT '14:00:00',
  `break_time` time NOT NULL DEFAULT '17:00:00',
  `lunch_minutes` int(11) NOT NULL DEFAULT '45',
  `break_minutes` int(11) NOT NULL DEFAULT '15',
  `meeting_minutes` int(11) NOT NULL DEFAULT '45',
  `scheduled_hours` decimal(5,2) NOT NULL DEFAULT '8.00',
  `overtime_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Activar calculo de horas extras',
  `overtime_multiplier` decimal(4,2) NOT NULL DEFAULT '1.50' COMMENT 'Multiplicador para pago de horas extras (ej: 1.5 = tiempo y medio)',
  `overtime_start_minutes` int(11) NOT NULL DEFAULT '0' COMMENT 'Minutos despues de la hora de salida para comenzar a contar horas extras',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `schedule_config`
--

INSERT INTO `schedule_config` (`id`, `entry_time`, `exit_time`, `lunch_time`, `break_time`, `lunch_minutes`, `break_minutes`, `meeting_minutes`, `scheduled_hours`, `overtime_enabled`, `overtime_multiplier`, `overtime_start_minutes`, `updated_at`) VALUES
(1, '10:00:00', '19:00:00', '14:00:00', '17:00:00', 45, 15, 45, 8.00, 1, 1.50, 0, '2025-10-28 20:34:36');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `schedule_templates`
--

CREATE TABLE `schedule_templates` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nombre del template (ej: Turno Mañana, Turno Tarde)',
  `description` text COLLATE utf8mb4_unicode_ci,
  `entry_time` time NOT NULL DEFAULT '10:00:00',
  `exit_time` time NOT NULL DEFAULT '19:00:00',
  `lunch_time` time DEFAULT '14:00:00',
  `break_time` time DEFAULT '17:00:00',
  `lunch_minutes` int(11) NOT NULL DEFAULT '45',
  `break_minutes` int(11) NOT NULL DEFAULT '15',
  `scheduled_hours` decimal(5,2) NOT NULL DEFAULT '8.00',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `schedule_templates`
--

INSERT INTO `schedule_templates` (`id`, `name`, `description`, `entry_time`, `exit_time`, `lunch_time`, `break_time`, `lunch_minutes`, `break_minutes`, `scheduled_hours`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Turno Regular (10am-7pm)', 'Horario estándar de 10:00 AM a 7:00 PM', '10:00:00', '19:00:00', '14:00:00', '17:00:00', 45, 15, 8.00, 1, '2025-11-03 13:54:58', NULL),
(2, 'Turno Mañana (7am-4pm)', 'Turno matutino de 7:00 AM a 4:00 PM', '07:00:00', '16:00:00', '12:00:00', '15:00:00', 45, 15, 8.00, 1, '2025-11-03 13:54:58', NULL),
(3, 'Turno Tarde (2pm-11pm)', 'Turno vespertino de 2:00 PM a 11:00 PM', '14:00:00', '23:00:00', '18:00:00', '21:00:00', 45, 15, 8.00, 1, '2025-11-03 13:54:58', NULL),
(4, 'Turno Noche (10pm-7am)', 'Turno nocturno de 10:00 PM a 7:00 AM', '22:00:00', '07:00:00', '02:00:00', '05:00:00', 45, 15, 8.00, 1, '2025-11-03 13:54:58', NULL),
(5, 'Medio Tiempo Mañana (8am-12pm)', 'Medio tiempo matutino', '08:00:00', '12:00:00', NULL, NULL, 0, 0, 4.00, 1, '2025-11-03 13:54:58', NULL),
(6, 'Medio Tiempo Tarde (1pm-5pm)', 'Medio tiempo vespertino', '13:00:00', '17:00:00', NULL, NULL, 0, 0, 4.00, 1, '2025-11-03 13:54:58', NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `section_permissions`
--

CREATE TABLE `section_permissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `section_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `section_permissions`
--

INSERT INTO `section_permissions` (`id`, `section_key`, `role`) VALUES
(582, 'activity_logs', 'Admin'),
(583, 'activity_logs', 'HR'),
(532, 'adherence_report', 'Admin'),
(533, 'adherence_report', 'HR'),
(534, 'adherence_report', 'IT'),
(574, 'agent_dashboard', 'Admin'),
(575, 'agent_dashboard', 'AGENT'),
(576, 'agent_dashboard', 'IT'),
(577, 'agent_dashboard', 'Supervisor'),
(578, 'agent_records', 'Admin'),
(579, 'agent_records', 'AGENT'),
(580, 'agent_records', 'IT'),
(581, 'agent_records', 'Supervisor'),
(503, 'dashboard', 'Admin'),
(504, 'dashboard', 'AGENT'),
(505, 'dashboard', 'GeneralManager'),
(506, 'dashboard', 'HR'),
(507, 'dashboard', 'IT'),
(508, 'dashboard', 'OperationsManager'),
(509, 'dashboard', 'QA'),
(510, 'dashboard', 'Supervisor'),
(540, 'download_excel', 'Admin'),
(541, 'download_excel', 'HR'),
(542, 'download_excel', 'IT'),
(543, 'download_excel_daily', 'Admin'),
(544, 'download_excel_daily', 'HR'),
(545, 'download_excel_daily', 'IT'),
(560, 'hr_birthdays', 'Admin'),
(561, 'hr_birthdays', 'HR'),
(562, 'hr_birthdays', 'IT'),
(569, 'hr_calendar', 'Admin'),
(570, 'hr_calendar', 'HR'),
(571, 'hr_calendar', 'IT'),
(548, 'hr_dashboard', 'Admin'),
(549, 'hr_dashboard', 'HR'),
(550, 'hr_dashboard', 'IT'),
(589, 'hr_employee_documents', 'Admin'),
(588, 'hr_employee_documents', 'HR'),
(341, 'hr_employee_profile', 'Admin'),
(342, 'hr_employee_profile', 'HR'),
(343, 'hr_employee_profile', 'IT'),
(551, 'hr_employees', 'Admin'),
(552, 'hr_employees', 'HR'),
(553, 'hr_employees', 'IT'),
(573, 'hr_job_postings', 'Admin'),
(497, 'hr_medical_leaves', 'Admin'),
(498, 'hr_medical_leaves', 'HR'),
(499, 'hr_medical_leaves', 'IT'),
(557, 'hr_payroll', 'Admin'),
(558, 'hr_payroll', 'HR'),
(559, 'hr_payroll', 'IT'),
(563, 'hr_permissions', 'Admin'),
(564, 'hr_permissions', 'HR'),
(565, 'hr_permissions', 'IT'),
(572, 'hr_recruitment', 'Admin'),
(529, 'hr_report', 'Admin'),
(530, 'hr_report', 'HR'),
(531, 'hr_report', 'IT'),
(554, 'hr_trial_period', 'Admin'),
(555, 'hr_trial_period', 'HR'),
(556, 'hr_trial_period', 'IT'),
(566, 'hr_vacations', 'Admin'),
(567, 'hr_vacations', 'HR'),
(568, 'hr_vacations', 'IT'),
(514, 'login_logs', 'Admin'),
(515, 'login_logs', 'IT'),
(535, 'operations_dashboard', 'Admin'),
(536, 'operations_dashboard', 'HR'),
(537, 'operations_dashboard', 'IT'),
(538, 'operations_dashboard', 'OperationsManager'),
(539, 'operations_dashboard', 'Supervisor'),
(516, 'records', 'Admin'),
(517, 'records', 'GeneralManager'),
(518, 'records', 'HR'),
(519, 'records', 'IT'),
(520, 'records', 'OperationsManager'),
(521, 'records', 'Supervisor'),
(522, 'records_qa', 'Admin'),
(523, 'records_qa', 'IT'),
(524, 'records_qa', 'QA'),
(525, 'records_qa', 'Supervisor'),
(546, 'register_attendance', 'Admin'),
(547, 'register_attendance', 'IT'),
(511, 'settings', 'Admin'),
(512, 'settings', 'IT'),
(513, 'settings', 'Supervisor'),
(586, 'system_settings', 'Admin'),
(587, 'system_settings', 'HR'),
(526, 'view_admin_hours', 'Admin'),
(527, 'view_admin_hours', 'HR'),
(528, 'view_admin_hours', 'IT');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_type` enum('string','number','boolean','json') COLLATE utf8mb4_unicode_ci DEFAULT 'string',
  `description` text COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `updated_at`, `updated_by`) VALUES
(1, 'exchange_rate_usd_to_dop', '64.25', 'number', 'Tasa de cambio de USD a DOP para cálculos de nómina y reportes', '2025-11-04 01:52:49', 1),
(2, 'exchange_rate_last_update', '2025-11-04 02:52:17', 'string', 'Última actualización de la tasa de cambio', '2025-11-04 01:52:49', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `employee_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `full_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Passwords are stored as plain text by the current application.',
  `role` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'AGENT',
  `hourly_rate` decimal(10,2) NOT NULL DEFAULT '0.00',
  `monthly_salary` decimal(12,2) NOT NULL DEFAULT '0.00',
  `hourly_rate_dop` decimal(12,2) NOT NULL DEFAULT '0.00',
  `monthly_salary_dop` decimal(14,2) NOT NULL DEFAULT '0.00',
  `preferred_currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `department_id` int(10) UNSIGNED DEFAULT NULL,
  `exit_time` time DEFAULT NULL COMMENT 'Hora de salida personalizada para este empleado',
  `schedule_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Horario actual del empleado',
  `overtime_multiplier` decimal(4,2) DEFAULT NULL COMMENT 'Multiplicador personalizado de horas extras (NULL = usar configuracion global)',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Estado del usuario: 1 = activo, 0 = inactivo',
  `reset_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token_expiry` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `users`
--

INSERT INTO `users` (`id`, `username`, `employee_code`, `full_name`, `password`, `role`, `hourly_rate`, `monthly_salary`, `hourly_rate_dop`, `monthly_salary_dop`, `preferred_currency`, `department_id`, `exit_time`, `schedule_id`, `overtime_multiplier`, `is_active`, `reset_token`, `token_expiry`, `created_at`) VALUES
(1, 'admin', 'EMP-2025-0001', 'System Administrator', 'admin123', 'Admin', 35.00, 0.00, 0.00, 0.00, 'USD', 1, NULL, NULL, NULL, 1, NULL, NULL, '2025-10-28 20:34:36'),
(4, 'hhidalgo', 'EMP-2025-0004', 'Hugo Hidalgo', 'defaultpassword', 'AGENT', 300.00, 0.00, 0.00, 0.00, 'USD', 5, NULL, NULL, NULL, 0, NULL, NULL, '2025-10-31 22:42:40'),
(5, 'Ncastillo', 'EMP-2025-0005', 'Nitida Ivelisse Castillo De La Rosa', 'Ivelisse042698', 'Supervisor', 0.00, 0.00, 144.23, 25000.00, 'DOP', 1, NULL, NULL, NULL, 1, NULL, NULL, '2025-11-03 16:39:01');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `vacation_balances`
--

CREATE TABLE `vacation_balances` (
  `id` int(10) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `year` int(11) NOT NULL,
  `total_days` decimal(5,2) NOT NULL DEFAULT '14.00',
  `used_days` decimal(5,2) NOT NULL DEFAULT '0.00',
  `remaining_days` decimal(5,2) NOT NULL DEFAULT '14.00',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
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
  `vacation_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'ANNUAL' COMMENT 'ANNUAL, UNPAID, COMPENSATORY',
  `reason` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING, APPROVED, REJECTED, CANCELLED',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `vw_medical_leaves_report`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `vw_medical_leaves_report` (
`id` bigint(20) unsigned
,`employee_id` int(10) unsigned
,`employee_code` varchar(20)
,`employee_name` varchar(201)
,`position` varchar(150)
,`department_name` varchar(150)
,`leave_type` varchar(50)
,`diagnosis` varchar(255)
,`start_date` date
,`end_date` date
,`total_days` decimal(5,2)
,`is_paid` tinyint(1)
,`payment_percentage` decimal(5,2)
,`status` varchar(50)
,`is_work_related` tinyint(1)
,`medical_center` varchar(200)
,`doctor_name` varchar(150)
,`reviewed_by_username` varchar(50)
,`reviewed_at` timestamp
,`created_at` timestamp
,`return_date` date
,`calculated_days` int(8)
);

-- --------------------------------------------------------

--
-- Estructura para la vista `vw_medical_leaves_report`
--
DROP TABLE IF EXISTS `vw_medical_leaves_report`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_medical_leaves_report`  AS SELECT `ml`.`id` AS `id`, `ml`.`employee_id` AS `employee_id`, `e`.`employee_code` AS `employee_code`, concat(`e`.`first_name`,' ',`e`.`last_name`) AS `employee_name`, `e`.`position` AS `position`, `d`.`name` AS `department_name`, `ml`.`leave_type` AS `leave_type`, `ml`.`diagnosis` AS `diagnosis`, `ml`.`start_date` AS `start_date`, `ml`.`end_date` AS `end_date`, `ml`.`total_days` AS `total_days`, `ml`.`is_paid` AS `is_paid`, `ml`.`payment_percentage` AS `payment_percentage`, `ml`.`status` AS `status`, `ml`.`is_work_related` AS `is_work_related`, `ml`.`medical_center` AS `medical_center`, `ml`.`doctor_name` AS `doctor_name`, concat(`reviewer`.`username`) AS `reviewed_by_username`, `ml`.`reviewed_at` AS `reviewed_at`, `ml`.`created_at` AS `created_at`, (case when (`ml`.`actual_return_date` is not null) then `ml`.`actual_return_date` when (`ml`.`expected_return_date` is not null) then `ml`.`expected_return_date` else `ml`.`end_date` end) AS `return_date`, ((to_days(`ml`.`end_date`) - to_days(`ml`.`start_date`)) + 1) AS `calculated_days` FROM (((`medical_leaves` `ml` join `employees` `e` on((`e`.`id` = `ml`.`employee_id`))) left join `departments` `d` on((`d`.`id` = `e`.`department_id`))) left join `users` `reviewer` on((`reviewer`.`id` = `ml`.`reviewed_by`))) ;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_module` (`module`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_created_at` (`created_at`);

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
-- Indices de la tabla `applicant_references`
--
ALTER TABLE `applicant_references`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`);

--
-- Indices de la tabla `applicant_skills`
--
ALTER TABLE `applicant_skills`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`);

--
-- Indices de la tabla `application_comments`
--
ALTER TABLE `application_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indices de la tabla `application_status_history`
--
ALTER TABLE `application_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `changed_by` (`changed_by`);

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
-- Indices de la tabla `banks`
--
ALTER TABLE `banks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_bank_name` (`name`);

--
-- Indices de la tabla `calendar_events`
--
ALTER TABLE `calendar_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_event_date` (`event_date`),
  ADD KEY `idx_event_type` (`event_type`),
  ADD KEY `idx_created_by` (`created_by`);

--
-- Indices de la tabla `calendar_event_attendees`
--
ALTER TABLE `calendar_event_attendees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_event_attendee` (`event_id`,`employee_id`),
  ADD KEY `idx_employee_id` (`employee_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indices de la tabla `calendar_event_reminders`
--
ALTER TABLE `calendar_event_reminders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_event_id` (`event_id`);

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
  ADD KEY `idx_employees_supervisor` (`supervisor_id`),
  ADD KEY `idx_employees_bank_id` (`bank_id`),
  ADD KEY `idx_employees_id_card` (`id_card_number`);

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
  ADD KEY `fk_employee_documents_uploader` (`uploaded_by`),
  ADD KEY `idx_employee_id` (`employee_id`),
  ADD KEY `idx_document_type` (`document_type`),
  ADD KEY `idx_uploaded_at` (`uploaded_at`);

--
-- Indices de la tabla `employee_health_stats`
--
ALTER TABLE `employee_health_stats`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_employee_year` (`employee_id`,`year`),
  ADD KEY `idx_health_stats_employee` (`employee_id`),
  ADD KEY `idx_health_stats_year` (`year`);

--
-- Indices de la tabla `employee_schedules`
--
ALTER TABLE `employee_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_employee_id` (`employee_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_effective_dates` (`effective_date`,`end_date`);

--
-- Indices de la tabla `employment_contracts`
--
ALTER TABLE `employment_contracts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_employee_id` (`employee_id`),
  ADD KEY `idx_contract_date` (`contract_date`),
  ADD KEY `idx_created_at` (`created_at`);

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
-- Indices de la tabla `job_applications`
--
ALTER TABLE `job_applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `job_posting_id` (`job_posting_id`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_application_code` (`application_code`);

--
-- Indices de la tabla `job_postings`
--
ALTER TABLE `job_postings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indices de la tabla `medical_leaves`
--
ALTER TABLE `medical_leaves`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_medical_leaves_employee` (`employee_id`),
  ADD KEY `idx_medical_leaves_user` (`user_id`),
  ADD KEY `idx_medical_leaves_dates` (`start_date`,`end_date`),
  ADD KEY `idx_medical_leaves_status` (`status`),
  ADD KEY `idx_medical_leaves_type` (`leave_type`),
  ADD KEY `idx_medical_leaves_reviewer` (`reviewed_by`),
  ADD KEY `idx_medical_leaves_start_date` (`start_date`),
  ADD KEY `idx_medical_leaves_active` (`status`,`end_date`);

--
-- Indices de la tabla `medical_leave_extensions`
--
ALTER TABLE `medical_leave_extensions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_extensions_leave` (`medical_leave_id`),
  ADD KEY `idx_extensions_requested_by` (`requested_by`),
  ADD KEY `idx_extensions_approved_by` (`approved_by`);

--
-- Indices de la tabla `medical_leave_followups`
--
ALTER TABLE `medical_leave_followups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_followups_leave` (`medical_leave_id`),
  ADD KEY `idx_followups_date` (`followup_date`),
  ADD KEY `idx_followups_recorded_by` (`recorded_by`);

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
-- Indices de la tabla `recruitment_interviews`
--
ALTER TABLE `recruitment_interviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `created_by` (`created_by`);

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
-- Indices de la tabla `schedule_templates`
--
ALTER TABLE `schedule_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_template_name` (`name`);

--
-- Indices de la tabla `section_permissions`
--
ALTER TABLE `section_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `section_role_unique` (`section_key`,`role`);

--
-- Indices de la tabla `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `idx_setting_key` (`setting_key`),
  ADD KEY `idx_updated_by` (`updated_by`);

--
-- Indices de la tabla `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_username_unique` (`username`),
  ADD UNIQUE KEY `employee_code` (`employee_code`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_department` (`department_id`),
  ADD KEY `idx_users_employee_code` (`employee_code`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `idx_schedule_id` (`schedule_id`);

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
-- AUTO_INCREMENT de la tabla `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `administrative_hours`
--
ALTER TABLE `administrative_hours`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `admin_login_logs`
--
ALTER TABLE `admin_login_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT de la tabla `applicant_references`
--
ALTER TABLE `applicant_references`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `applicant_skills`
--
ALTER TABLE `applicant_skills`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `application_comments`
--
ALTER TABLE `application_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `application_status_history`
--
ALTER TABLE `application_status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT de la tabla `attendance_types`
--
ALTER TABLE `attendance_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `banks`
--
ALTER TABLE `banks`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de la tabla `calendar_events`
--
ALTER TABLE `calendar_events`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `calendar_event_attendees`
--
ALTER TABLE `calendar_event_attendees`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `calendar_event_reminders`
--
ALTER TABLE `calendar_event_reminders`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `employee_deductions`
--
ALTER TABLE `employee_deductions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `employee_documents`
--
ALTER TABLE `employee_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `employee_health_stats`
--
ALTER TABLE `employee_health_stats`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `employee_schedules`
--
ALTER TABLE `employee_schedules`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `employment_contracts`
--
ALTER TABLE `employment_contracts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

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
-- AUTO_INCREMENT de la tabla `job_applications`
--
ALTER TABLE `job_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT de la tabla `job_postings`
--
ALTER TABLE `job_postings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `medical_leaves`
--
ALTER TABLE `medical_leaves`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `medical_leave_extensions`
--
ALTER TABLE `medical_leave_extensions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `medical_leave_followups`
--
ALTER TABLE `medical_leave_followups`
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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `payroll_records`
--
ALTER TABLE `payroll_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `permission_requests`
--
ALTER TABLE `permission_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `recruitment_interviews`
--
ALTER TABLE `recruitment_interviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT de la tabla `salary_history`
--
ALTER TABLE `salary_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `schedule_templates`
--
ALTER TABLE `schedule_templates`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `section_permissions`
--
ALTER TABLE `section_permissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=592;

--
-- AUTO_INCREMENT de la tabla `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `vacation_balances`
--
ALTER TABLE `vacation_balances`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `vacation_requests`
--
ALTER TABLE `vacation_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

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
-- Filtros para la tabla `applicant_references`
--
ALTER TABLE `applicant_references`
  ADD CONSTRAINT `applicant_references_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `job_applications` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `applicant_skills`
--
ALTER TABLE `applicant_skills`
  ADD CONSTRAINT `applicant_skills_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `job_applications` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `application_comments`
--
ALTER TABLE `application_comments`
  ADD CONSTRAINT `application_comments_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `job_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `application_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `application_status_history`
--
ALTER TABLE `application_status_history`
  ADD CONSTRAINT `application_status_history_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `job_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `application_status_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `fk_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `calendar_events`
--
ALTER TABLE `calendar_events`
  ADD CONSTRAINT `calendar_events_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `calendar_event_attendees`
--
ALTER TABLE `calendar_event_attendees`
  ADD CONSTRAINT `calendar_event_attendees_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `calendar_event_attendees_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `calendar_event_reminders`
--
ALTER TABLE `calendar_event_reminders`
  ADD CONSTRAINT `calendar_event_reminders_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `fk_employees_bank` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
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
-- Filtros para la tabla `employee_health_stats`
--
ALTER TABLE `employee_health_stats`
  ADD CONSTRAINT `fk_health_stats_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `employee_schedules`
--
ALTER TABLE `employee_schedules`
  ADD CONSTRAINT `fk_employee_schedules_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_employee_schedules_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `employment_contracts`
--
ALTER TABLE `employment_contracts`
  ADD CONSTRAINT `employment_contracts_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `employment_contracts_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

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
-- Filtros para la tabla `job_applications`
--
ALTER TABLE `job_applications`
  ADD CONSTRAINT `job_applications_ibfk_1` FOREIGN KEY (`job_posting_id`) REFERENCES `job_postings` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `job_applications_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `job_postings`
--
ALTER TABLE `job_postings`
  ADD CONSTRAINT `job_postings_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `medical_leaves`
--
ALTER TABLE `medical_leaves`
  ADD CONSTRAINT `fk_medical_leaves_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_medical_leaves_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_medical_leaves_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `medical_leave_extensions`
--
ALTER TABLE `medical_leave_extensions`
  ADD CONSTRAINT `fk_extensions_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_extensions_leave` FOREIGN KEY (`medical_leave_id`) REFERENCES `medical_leaves` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_extensions_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `medical_leave_followups`
--
ALTER TABLE `medical_leave_followups`
  ADD CONSTRAINT `fk_followups_leave` FOREIGN KEY (`medical_leave_id`) REFERENCES `medical_leaves` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_followups_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `permission_requests`
--
ALTER TABLE `permission_requests`
  ADD CONSTRAINT `fk_permission_requests_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_permission_requests_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_permission_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `recruitment_interviews`
--
ALTER TABLE `recruitment_interviews`
  ADD CONSTRAINT `recruitment_interviews_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `job_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `recruitment_interviews_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `system_settings`
--
ALTER TABLE `system_settings`
  ADD CONSTRAINT `fk_system_settings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

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
