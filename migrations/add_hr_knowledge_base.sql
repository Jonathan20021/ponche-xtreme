-- Migration: Add HR Knowledge Base table
-- Description: Creates a knowledge base table for the HR virtual assistant
-- Date: 2025-11-03

-- Create hr_knowledge_base table
CREATE TABLE IF NOT EXISTS hr_knowledge_base (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question VARCHAR(500) NOT NULL,
    answer TEXT NOT NULL,
    category VARCHAR(100) DEFAULT 'general',
    keywords TEXT,
    priority INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_priority (priority),
    INDEX idx_active (is_active),
    FULLTEXT INDEX idx_search (question, answer, keywords)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert common HR questions and answers
INSERT INTO hr_knowledge_base (question, answer, category, keywords, priority) VALUES
('¿Cuántos días de vacaciones tengo?', 'Los empleados tienen derecho a 14 días de vacaciones por año trabajado. Puedes consultar tu balance actual en el sistema o preguntarme directamente.', 'vacaciones', 'vacaciones, días, balance, disponibles', 10),
('¿Cómo solicito vacaciones?', 'Para solicitar vacaciones, debes ir al módulo de Recursos Humanos > Vacaciones, hacer clic en "Nueva Solicitud" y completar el formulario con las fechas deseadas. La solicitud debe hacerse con al menos 15 días de anticipación.', 'vacaciones', 'solicitar, vacaciones, proceso, formulario', 10),
('¿Cómo solicito un permiso?', 'Para solicitar un permiso, ve a Recursos Humanos > Permisos y completa el formulario de solicitud. Debes hacerlo con al menos 48 horas de anticipación. Especifica el tipo de permiso, fechas y motivo.', 'permisos', 'permiso, solicitud, proceso, formulario', 10),
('¿Cuál es el horario de trabajo?', 'El horario estándar es de 10:00 AM a 7:00 PM, con 45 minutos de almuerzo a las 2:00 PM y 15 minutos de break a las 5:00 PM. Algunos empleados pueden tener horarios personalizados.', 'horario', 'horario, trabajo, entrada, salida, almuerzo', 9),
('¿Qué hago si llego tarde?', 'Si vas a llegar tarde, debes notificar a tu supervisor inmediatamente. Las llegadas tarde frecuentes pueden afectar tu evaluación de desempeño. Se recomienda solicitar un permiso si sabes que llegarás tarde.', 'asistencia', 'tarde, retraso, llegada, puntualidad', 8),
('¿Qué hago si estoy enfermo?', 'Si estás enfermo, debes notificar a Recursos Humanos y a tu supervisor lo antes posible. Si la ausencia es mayor a 2 días, deberás presentar un certificado médico al regresar.', 'ausencias', 'enfermo, ausencia, certificado médico, salud', 9),
('¿Cuándo son las evaluaciones de desempeño?', 'Las evaluaciones de desempeño se realizan cada 6 meses. Recibirás una notificación con anticipación sobre la fecha de tu evaluación. Puedes consultar tu próxima evaluación en el sistema.', 'evaluaciones', 'evaluación, desempeño, revisión, feedback', 8),
('¿Cómo actualizo mis datos personales?', 'Para actualizar tus datos personales (dirección, teléfono, contacto de emergencia), debes contactar a Recursos Humanos directamente o enviar un correo a rh@evallishbpo.com con la información actualizada.', 'datos', 'datos personales, actualizar, información, contacto', 7),
('¿Cuáles son los beneficios de la empresa?', 'Los beneficios incluyen seguro médico, días de vacaciones, bonos por desempeño, capacitaciones, y eventos de integración. Para detalles específicos de tus beneficios, consulta tu perfil de empleado.', 'beneficios', 'beneficios, seguro, bonos, capacitación', 8),
('¿Cómo accedo a mi recibo de pago?', 'Los recibos de pago están disponibles en el módulo de Recursos Humanos > Nómina. Puedes descargar tus recibos en formato PDF. Los pagos se realizan quincenalmente.', 'nomina', 'recibo, pago, nómina, salario, sueldo', 9),
('¿Qué documentos necesito presentar?', 'Los documentos requeridos incluyen: cédula, certificado médico, certificado de antecedentes penales, comprobante de domicilio, y referencias laborales. Puedes subirlos en tu perfil de empleado.', 'documentos', 'documentos, requisitos, papeles, cédula', 7),
('¿Cómo funciona el período de prueba?', 'El período de prueba es de 90 días. Durante este tiempo, se evaluará tu desempeño y adaptación al puesto. Recibirás retroalimentación regular de tu supervisor.', 'periodo_prueba', 'período de prueba, prueba, evaluación inicial', 7),
('¿Puedo trabajar horas extras?', 'Las horas extras deben ser autorizadas previamente por tu supervisor. Se pagan con un multiplicador de 1.5x tu tarifa horaria normal. Consulta con tu supervisor sobre la disponibilidad.', 'horas_extras', 'horas extras, overtime, tiempo extra', 8),
('¿Cómo reporto un problema técnico?', 'Para problemas técnicos con el sistema, contacta al departamento de IT o envía un correo a soporte@evallishbpo.com describiendo el problema en detalle.', 'soporte', 'problema técnico, error, sistema, IT, soporte', 6),
('¿Dónde encuentro las políticas de la empresa?', 'Las políticas de la empresa están disponibles en el manual del empleado que recibiste al ingresar. También puedes solicitarlas a Recursos Humanos en cualquier momento.', 'politicas', 'políticas, manual, reglamento, normas', 7);

-- Create chat_history table for storing conversations (optional)
CREATE TABLE IF NOT EXISTS hr_assistant_chat_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT(10) UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    response TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at),
    CONSTRAINT fk_chat_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
