-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost:3306
-- Tiempo de generación: 02-02-2026 a las 11:15:30
-- Versión del servidor: 5.7.23-23
-- Versión de PHP: 8.1.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `hhempeos_calidad`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ai_evaluation_criteria`
--

CREATE TABLE `ai_evaluation_criteria` (
  `id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `campaign_id` int(11) DEFAULT NULL,
  `call_type` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `criteria_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `calls`
--

CREATE TABLE `calls` (
  `id` int(11) NOT NULL,
  `agent_id` int(11) NOT NULL,
  `campaign_id` int(11) NOT NULL,
  `call_datetime` datetime NOT NULL,
  `duration_seconds` int(11) DEFAULT NULL COMMENT 'Duration in seconds',
  `customer_phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `recording_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `project_id` int(11) DEFAULT NULL,
  `call_type` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `calls`
--

INSERT INTO `calls` (`id`, `agent_id`, `campaign_id`, `call_datetime`, `duration_seconds`, `customer_phone`, `notes`, `recording_path`, `created_at`, `updated_at`, `project_id`, `call_type`) VALUES
(1, 2, 1, '2026-01-22 18:57:00', 560, '8495024061', 'BUENA GESTION', 'uploads/calls/call_20260122_185744_4b10bd0f11e1.mpeg', '2026-01-23 05:57:44', '2026-01-23 05:57:44', NULL, NULL),
(2, 5, 4, '2026-01-26 14:17:00', 879, '', '', 'uploads/calls/call_20260126_141755_70bf8841c37c.mp3', '2026-01-26 19:17:55', '2026-01-26 19:17:55', NULL, NULL),
(3, 5, 1, '2026-01-26 14:35:00', 879, '', '', 'uploads/calls/call_20260126_143619_cf601763666b.mp3', '2026-01-26 19:36:19', '2026-01-26 19:36:19', NULL, NULL),
(15, 65, 16, '2026-02-02 11:24:00', 95, '8298207600', '', 'uploads/calls/call_20260202_112543_bd29fde5acdf.mp3', '2026-02-02 16:25:43', '2026-02-02 16:25:43', 1, 'ventas');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `call_ai_analytics`
--

CREATE TABLE `call_ai_analytics` (
  `id` int(11) NOT NULL,
  `call_id` int(11) NOT NULL,
  `model` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `summary` text COLLATE utf8mb4_unicode_ci,
  `metrics_json` longtext COLLATE utf8mb4_unicode_ci,
  `raw_response_json` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `call_ai_analytics`
--

INSERT INTO `call_ai_analytics` (`id`, `call_id`, `model`, `score`, `summary`, `metrics_json`, `raw_response_json`, `created_at`, `updated_at`) VALUES
(1, 1, 'gemini-3-flash-preview', 85.00, 'El agente contactó al cliente tras recibir un formulario para consolidación de deudas. A pesar de que el agente explicó los beneficios y la brevedad de la asesoría gratuita, el cliente declinó la oferta indicando que ya conocía el programa y no estaba interesado. El agente manejó la objeción brevemente y cerró solicitando referidos.', '{\"overall_score\":85,\"summary\":\"El agente contactó al cliente tras recibir un formulario para consolidación de deudas. A pesar de que el agente explicó los beneficios y la brevedad de la asesoría gratuita, el cliente declinó la oferta indicando que ya conocía el programa y no estaba interesado. El agente manejó la objeción brevemente y cerró solicitando referidos.\",\"sentiment\":\"neutral\",\"agent_strengths\":[\"Saludo e identificación claros\",\"Explicación precisa del motivo de la llamada\",\"Intento de manejo de objeciones\",\"Cierre profesional y cortés\",\"Solicitud de referidos\"],\"agent_opportunities\":[\"Indagar más sobre el motivo específico del desinterés\",\"Resaltar un beneficio único para captar la atención tras la primera negativa\"],\"compliance\":{\"saludo\":\"si\",\"identificacion\":\"si\",\"verificacion\":\"si\",\"escucha_activa\":\"si\",\"empatia\":\"si\",\"cierre\":\"si\",\"politica\":\"si\"},\"critical_issues\":[],\"coaching_tips\":[\"Reforzar técnicas de persuasión cuando el cliente dice que ya conoce el producto\",\"Mantener el control de la llamada mediante preguntas abiertas tras una negativa\"],\"call_tags\":[\"Ventas Inbound\",\"Consolidación de deuda\",\"Objeción\",\"Referidos\"],\"next_best_actions\":[\"Marcar lead como no interesado\",\"Programar revisión de base de datos para evitar re-contactos innecesarios\"]}', '{\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"{\\n  \\\"overall_score\\\": 85,\\n  \\\"summary\\\": \\\"El agente contactó al cliente tras recibir un formulario para consolidación de deudas. A pesar de que el agente explicó los beneficios y la brevedad de la asesoría gratuita, el cliente declinó la oferta indicando que ya conocía el programa y no estaba interesado. El agente manejó la objeción brevemente y cerró solicitando referidos.\\\",\\n  \\\"sentiment\\\": \\\"neutral\\\",\\n  \\\"agent_strengths\\\": [\\n    \\\"Saludo e identificación claros\\\",\\n    \\\"Explicación precisa del motivo de la llamada\\\",\\n    \\\"Intento de manejo de objeciones\\\",\\n    \\\"Cierre profesional y cortés\\\",\\n    \\\"Solicitud de referidos\\\"\\n  ],\\n  \\\"agent_opportunities\\\": [\\n    \\\"Indagar más sobre el motivo específico del desinterés\\\",\\n    \\\"Resaltar un beneficio único para captar la atención tras la primera negativa\\\"\\n  ],\\n  \\\"compliance\\\": {\\n    \\\"saludo\\\": \\\"si\\\",\\n    \\\"identificacion\\\": \\\"si\\\",\\n    \\\"verificacion\\\": \\\"si\\\",\\n    \\\"escucha_activa\\\": \\\"si\\\",\\n    \\\"empatia\\\": \\\"si\\\",\\n    \\\"cierre\\\": \\\"si\\\",\\n    \\\"politica\\\": \\\"si\\\"\\n  },\\n  \\\"critical_issues\\\": [],\\n  \\\"coaching_tips\\\": [\\n    \\\"Reforzar técnicas de persuasión cuando el cliente dice que ya conoce el producto\\\",\\n    \\\"Mantener el control de la llamada mediante preguntas abiertas tras una negativa\\\"\\n  ],\\n  \\\"call_tags\\\": [\\n    \\\"Ventas Inbound\\\",\\n    \\\"Consolidación de deuda\\\",\\n    \\\"Objeción\\\",\\n    \\\"Referidos\\\"\\n  ],\\n  \\\"next_best_actions\\\": [\\n    \\\"Marcar lead como no interesado\\\",\\n    \\\"Programar revisión de base de datos para evitar re-contactos innecesarios\\\"\\n  ]\\n}\",\"thoughtSignature\":\"EsQoCsEoAXLI2nwT\\/Nz5uLgsLF0QeK5QQ7zEuRsMvF3oxKiK\\/s99o+Qf2LANO9XKiPlQknz1YS0Zagls9noa+je9GVW1iPuhHt0KRXgEFT7WFgWBREgosfNO0bjzO9iz9\\/I0WPB2iz2bIDwmLRBiJmIlv2k0sEIQBTBVHk1hAMr\\/oJa1dTm\\/QwUR5Gh6IUgG6ou4LSFTQTEK+jpN0w5i+Yx7NgB9LDtqbQcuRd2UliHsX71eJ\\/pBaVbp2t6TWtPMIsNBt2N\\/ot1gXhPIT0KGScrwD9Ayq5A6pW99GefIp7kPuvktwExoZgJOv1\\/1Ydl0ExPBjLWryCuRax73fZjgIsEc1fULk5wjceXMPEUvG9qYjT01slf6YLJYy3xNgYFsL9c2VwJpD0uRBmYdcE1VOpqpOy4NOenyNQmTzeVMyn2Tp1eV0wXKUmEyFZH3tLbvyXznDsVYvwc0brUVv0zjPWRrRXXqpR16I3lbKvw98Jo215oeQCJjEkNXd\\/qFHBNN227w24DYKWhL\\/yKSITFLsSliQtk9aNCBgIO8hopSBk6luVHL9BCHHjHvlKFOQ5iPTZMGLCx6JxMtOHbc7gwXGJC9Dxvx7nl4QSzqIvQEZ1V0Ht8bSibgSDCo1oaMI3GRqxyaeo5Zs0wZgipjV5up4EUqHWLBaZyPj4R94kICdrrw4YvkdHrfGtsoEmk8y0PlyZhVTz9ghzqX3EB2NZSUFqMrFQknB6VTi9hfBSKMiniKMtLOHrY0rhB31y7S8D8rwy5OIPHr55ald9c1psl8aVqoMbMxGd2O1OK4zft63YcDiRBZhoILPahTtTp6Ha6Ui2nYBfqlpo7FnmxMnuvxQIygvTWuDs4Wu2BPckzyD8P0nLynomK\\/tepX3NlaLuaC36b7K6DPp2KyLcgyFR8wkau92Fck\\/WxaOR7L2OJ7AVz00GtlRfwQjlIRMS7u\\/wL1kXsmAYisdsBu15i5XIh3W1hl9kZ5OidoMK1rv34GGFbwjk8oZWaJZkb0YMTdS0MeP3hYz+gOdFbTliT35+uaiZ9J68kYyWPyEEt\\/v5aNGDyNqn3S2bI+pOrw1QNEEAHBVV3VHDqCG+CdM\\/6Re0wp4chfabqrDK791EqOVliR3zsSvHPYK1fWZOhtJumoChslDnxrNLGoH+Pxds+Rwub8fmCN0zEr78xVpsYO4iH\\/aGSzLCy6MZ7ww7KIz7NFmlvflT9h9UezsNHxrQqUfScNLStt8qbeH9egjdZXiEktemKP3DSfFc2NKvPMLzxOWAz\\/kkK\\/A8R\\/G\\/v7dv4Z+1IyoupmTLS\\/fjuOGFHPzfcx+j7nKsnsU2JC+PdQ9zcmxDeYTEDk2ZhEPnFP2i8e37iUhS68f5xDSWptbxI8JdbxKASS0tiEhI3RqAPNF+p+A40\\/IE843129e7f8L9+4aKLhP53HJZ5GF\\/B78vMe8AxvVhMvAY\\/1wcGCI5rH64UgbwNop7UIXlr+uBSvM92l21jJ4nJxjHvFQVpaVCQsa2Z\\/hBB3DtWLDpD2q8bPsJEO+pSr0LRCowIvmyvYXm+lb1wTsKo5eKXnjN0jb\\/xD0UCOLgQemsLNaigBwb+3McrbLcPpT1RULvkn2Syz7CvC\\/Ot3Auh7CbQaB2mfPZE5ptyP0ZdW7Cp6fQTTULNjlFg244RSv70HhG4J3Pgk7EnzYcjEM9OsRzPYqpsFFvqknqyd+d8+D6qc5zjSwLJ3EkZguMykrkX2O2x7hs27nFt\\/SpaLwDyk9AVMx2wpdbgzLQ8EJWifX0mhFpMmKzRc\\/sbkHwKdkl5v+ZUQNZsAZmq73DLVr4lfH9FRNlsobBV9\\/iIFNiwmARuGkCGJye+dQAPEjtk499gHg+uaW5Z89Wr2T++a08xs8yKmHUZZ6uk9H\\/6TjEGXkEbSuH4UwkSNpFLCbzUKHBGdUcVKxMIl6jQQ0cgYklhfDHd8YNaBArIsdhrp5HY1HVnMnXisoEW0lOj8fjI8t4oEjH+LDV5qHHanzNvs887YoCAW5aUyi0u+QI0\\/vp+WYv3x8IRtllcrgnjzw0Vt\\/TUY3F4h0alfnOQFuQoAAGA7sVgPmt3xe\\/hyF8wPHBB+ofsKVpEn81iKVYa+qtjTwbpg70bZ9fGEhf2xTDk7jrFiUdiJPv5nK1w+FSLMQgnqgdIE1C7MpWnNSw8rvXQfF6ISVOdocquNdwE3O+vSjspwjd\\/v6WTDT\\/7\\/xIKCifV7Gr\\/GZjhJ3wlJZYwdIoA9W4sBlaE2TQmC9H3zGCgYOIfRBWJLlwVba+quKYWC3sea9Bm1oyIsSrazPH26X7evwqcE82ZVCyLaENUeXKI9IiJcFE5wMUiJvBiUf4B++D1R0aMnLT0clr\\/RaXmlVhn1a4uzTFlEACH5uB\\/Zw6c6AJOyO3jdkkGOfH6bTLOTzYEAZJfszSEiQUsDmOcsW6b5ma\\/mC+UWFZT2JuUi2bw5KM1qqVUWu3iz+bjCs5g0TO4+J5elT9x0nWbNN6yK9o\\/DCYUZZ8MutRAHYV4PtctLCZChDmAA4IJYMmgMNTBeGXjCrxnI7dsNktgXNb7x8+EJuzA7HeU8pjgZGkgmk6RqO551IfW3yarlxogtpR9Tw1ejYS\\/ZMTDHJ6yo91zj83zG3Z6ocC7+P7iL9VPgyO7GlWMaZma5457I7OrRcrJRNynScr5WXm3uR5iV5bQJdZY6kcUuGDAjXfmXbUY7\\/KEVQLCEhH7H1UJeG7vt0D2ySvtPpJ8WmBMeY1kA7EFCjjF2NiKOTCKtWxMYWaQ5r2g+xeCGOA75+Vm+vy5anXHHIxi2+vsncKFxIM2QzpxQ0CL5kdF3CsU3yqmAsv6khxafhWyjtmm0fsqWvk8PHGvtbO1EgL47WX1lL5CKNog4IsZQgmnnAPaT1a39ZWuDfai0S+7ob1kDteff5dyoB9GfGcMBEeJnf6YQecqbcxgVmA8vL6BG7nBNMJ8sDMKUZ4ta45isFLa\\/WCRBjh5eOgYPoJU1yHrxL77CM9Eeq5zamGCcbFsxCPo6x32F3K1CYTz6rqlxoKnI0wQIKHRDMWYz0cgUS3FKE9GUp2rUMiB2d7XgRwvvi+U6Vh5UcHW6l+ObwhsDqcCua1BIVTAv1YOQXfVkP\\/JklRVDX50yhta9xnhzoKahyw2+D9Z9H\\/P5yMhAdq1bIQF\\/dJLx+95frFhD8nBlZ9XTS8L8qimv+cEXbodLE\\/6XF3Tbgu4+SI5FVpzBdl7I\\/d\\/MFrBifzuvoIvw2FFNynMGAtoX5XfzemwspsGrMBRJ6rQoOFECogULdYIdw3gjvatTc9X\\/CCJBRfoIBvddclNWBsaSahwYukJBoidCn4kRNEP53v9Kbcmma5AZ\\/DJpHOhL550yGfDMDzdMbcCCESO8sXRd907EDqDu7RYRrgK6G1Mxhlc6rcK3YxLg9d1SrFAhSDGpzDv9zwJqNhASTdjX51n\\/KtA8XA2wOvI7+fECxO5DLXxRW+VkesB4xNQEvuXdDwZ+eZQsLqTJr3uye3JuGFLi5j8R9T7eAXer5JBiLTVYTZk9P81iCJef4GwcCxsxizM5mFWfGrqNDIIHJZKcoK6Sxrvx23qO+8hS4tYy800SzvpqCQIGrgzoHEywwQr8GfAhLZRSXg5ZXbcCiHbCeIMGSXAeTUgeFSxbQ631s5MC9r1LDTuZmRBAioxhDzew\\/COMTo5zvkYqZpAzR2GMuMfGPElnOCsJGDb4Nolk9ZE4wIjomBwbgNc3FH9kHHA90CT+I7jXRv7UUM0Sl5vkYU3zIREGsxBvJvui7i+BIxVD\\/x3q9rPtqgOe0du6t3xWUsslWedZbbvdlhL8H7t5Ke81yodckpbOBlUfMHNvZN7jpXNO32dBj7Zuq3R3pJizf8tSeA1T6FTrNUbOFgbEjgMZ2qoifm3zvYJg4voq7LX7fcKvn12Q6iPLbAZWV2IqJhAmfrAr6ouNMEVmNpEPB9ZaZXWCpBsOC+C76PXjbyIJe5ClUK1aS7DqFCNynnzgx+BXuTETiyq4ieFVskzx2vZhbOVXzWdwsSHqOzqwMR8f6vlHMRZ4FTirbMV18AxQhkuk4+2MKfIXCFIe3YS1tO88nZwKVDKjfuMfNfkfsSmQltLsd5Q1CGmAeKOGXvMvNz3ZDyupshAxEN\\/uOKLZtebZcjii7ew8hc8oEvlNpxN1rbbKC6SfkL+2lvNdRdxkTcrn5UbY5w0QGo9a5jkdEtIX1BEwIyU5HZvMD9\\/4ajy0iSr+JlRvVb3pDQHK2YllMqKDXLNtcjro8GOHJYjnmlzbeF4\\/M4HewNQ+VMxp6\\/t9VqYf9FKR+qNRomn5vevHMXaHk6CaGn8+GXI0DlFXsAa0DbHTgHCBEuI7UWP25Wn0MWLHJ7Bc5m\\/p6QlTXaIPgpz+cs7H5FNgUOn+VvmZ2z2EjAfJfw5hCt7qhFYOYGuO40mCbO7ZPGA+3yKPl2Bho9os\\/yLbs7PBZL1lxzEujp8hNxEvKELV37zALngqjwCN424eYkiSj4VjCcTI5NV7BVpLDipXws1m8wWBv5RBIoPhmKTjDUdxK8E98R9jFEsJDqLTV2vgvlNc2YEfaO8aH15KcGo9MhoAc3A4aJBw1ku44xvsz2VgyVOuESdcipjmoSJDXVd59U5fA43xsuCwVmadPyDC9sRPID7gIXkUuOVZFcG1zOBxo8G0T3qM99xC0ltMYztMV3QEJM8Lh0zwH3wZVjowbWk4\\/62DqZN71\\/ESNo7UCWsM+96iOvIt6S9avKlbyeAF6QBTXb042kL4yvOV03G6AqqXp56SHqw5DCgorvIUArl4JXvXSxSRPZeCtM6Ww+qRTHKOuTEV0\\/28BXlk7eKEQVN\\/Q7j\\/Kmbrn6AIqr0KSp68ioWgcGfdMsCSSAmNE6r0KrpsHpYUl5YITX13LuAGB13kpTjpAw4\\/8di5GvWpHnrbwtR+v7pEE2YYj0VixMkwgkul03amijjLu4Kr30IbvKNp1srgV7H2VM\\/k9jtntZczp\\/Ea6aqo0RBzBIsWUpzvp8SfRUW+d1aVeZFqVZgNeiCC+qcaXnG+JiQfRX8fj+C+iFQ13pIOx5GgKFlSap0j7QLPDw2DUj0lPerLyIF8Rdk59cpRHJtigm7r0VzPzgYxYjBtQ+WqfqkiciIUok1iZXC1vdqVk7DZERxqAaLcvtFseuXZi3ud1Wcj0oFEsC0XQ5YYRZfFfLeKGae6XbTMfgScqmCqxH9tEqhErpGfFr0OZisAfr6gzGI\\/inN0JT6\\/sTlRMoKWy239c3\\/Q+wlzyJ7xK+aDg7QQhnndnON90DbbEvlX2H9\\/ncL0+wKn6WJlGIyzcCStR1Tbs5qnTfFHzEzNGy8Ou0HnkR8WuYP7R2EGOZ26ulZ34lF5ePM8Cz9H6cjlVHMTYNoiDaTZRuX3A1uMVfJb\\/AAtVhAIqzHGsRkATxNa3eIeP\\/crMnFQVMgoqZ+fzOb2N5S93IkqEDvHDYc4or1zThi6Dbg6Ajsv3g3pxUj1A5mAPDoECCbQLBcXFiMqhR4+tNf7tb3eZQx19dre66b6OO7llQnMhVsInfh3SGm8D1smLX+16cCfCUaAXLOhkJC1zdehTGf\\/ao1\\/LB2ruwNKyHxZMv\\/Khxn83oczZt4oDw84q2BiFHxoHZtNZZWQxFXcijNEnTMKyUfrP78WVs2YsNVcjRA0vczKj5aBDmtOeUkyfwwXCG5RGKPfTGjvWn72LKrBo9mjs9g75FP87sLc5olaXgf7t991\\/CXCgFXqg+Jx5h6YrxvndJk46b10RQPZfKY56grV\\/UxzU9B8BJY9exd0CUqiao9RctkoASk+cvrr1bPHPsYBv492hkEZwJI\\/M0RqZF1vrHrqdbLFWloQ8ulK+697xPoUM4yJdXBULehrCA9a7t5xY5xiirYmCpbHOEB9xIqSH0J6fS6ZzgHnLJ+UvOfAo1K+5nv94aDSlx8RqBPGrvJhxeGcW5vk2xbQ4cYXCRy\\/wr3C3e\\/e6hvatuvqjmeDG7oCE7xb9BbzjAxqkojSLUhVmEaHXBj5k+mScnX8YPgc7yLj3syFhMbMbGZYcOXlMkQwoJ3iNgLOHHRhxsefP6bTXEXFYWaXukf0ps1zE7NbqBTa4C1hFH5rHXmUAfL\\/rbt5wvsmmYTKb1qYBHEOjwmEnI8vFdpwA1aZN1afTuhHjg\\/3gir4aqrMjfbQcfO9EJ5MzRiWm87UQKaetTOqljtGemHAEl7KxzKR4IUkCzRChw92djt4fC1INHb4fTrCAQR2i8xzvQRHrTuT7qR1kfCSG3HyOiT1Qvgo\\/LS4+QQz5lgTc30tiHnCAbr9P7bIVobyzQ4qb\\/fGh2NexJoAw0fQchNe3HULfPey4s\\/v7h33Xd5lEAd9CiWnu2WDDA+vWyKXRs6QRCIvpJFHAMKQ\\/hemnbOh0jgqN8h9v12iSmQFHpi57b2gUAUO2xjjmmHHqy8f2xQbMUhGHkEIs+07mO7k0AkVoeZVpF5Cj8gG+RnDVdvEoAvC2U9c+a33vTghqavPyV9+k0Ec50Ekfod9SVzH0Rvmm9c01SWz3ddbyo6h3z+zuQ2FxjrPpQ+FHR3mvbj5ct5Ys4R9AlPFWWSSG7EydDznvlc6bYrhNGtFvyja1LjfCeNaw955VvM2Q3\\/qzpf4YwBkwYm0PB+CbwX2i5yeweVJrzj2FGDNZNCuvLeXAic5yeVRvunZj3yUW0nRlf3fKYCcvLNgnV9BlcVJ6IsrwtsTT4i9KeeTWXWfgeUwx+yACC0iUPr8ArFbSsgXu6RvvhHoC0SH43t40o\\/ARId\\/HmPrFSuYhHQQLR3GyzRY375C1xZnRRynoFxx8SFzKT\\/97KUNPQoOt1aVk3WKFjsfyM1vBoXPNDm4iET\\/pEg4NP08eupqb3pu2ynPAeD8YeI5kzbO0j+T\\/skZ6AFWUf\\/AiW+k5WTx9K4YrWRmkg==\"}],\"role\":\"model\"},\"finishReason\":\"STOP\",\"index\":0}],\"usageMetadata\":{\"promptTokenCount\":2230,\"candidatesTokenCount\":427,\"totalTokenCount\":4021,\"promptTokensDetails\":[{\"modality\":\"AUDIO\",\"tokenCount\":2043},{\"modality\":\"TEXT\",\"tokenCount\":187}],\"thoughtsTokenCount\":1364},\"modelVersion\":\"gemini-3-flash-preview\",\"responseId\":\"UNV1abzkJaDUz7IPg9bs8AQ\"}', '2026-01-23 22:16:23', '2026-01-25 14:33:19');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `campaigns`
--

CREATE TABLE `campaigns` (
  `id` int(11) NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `campaigns`
--

INSERT INTO `campaigns` (`id`, `name`, `description`, `active`, `created_at`, `updated_at`) VALUES
(1, 'Ventas Inbound', 'Campaña de ventas entrantes', 1, '2026-01-23 01:37:47', '2026-01-23 01:37:47'),
(2, 'Soporte Técnico', 'Campaña de soporte al cliente', 1, '2026-01-23 01:37:47', '2026-01-23 01:37:47'),
(3, 'Retención', 'Campaña de retención de clientes', 1, '2026-01-23 01:37:47', '2026-01-23 01:37:47'),
(4, 'Encuestas', 'Encuestas Telefonicas', 1, '2026-01-26 17:27:02', '2026-01-26 17:27:02'),
(13, 'Encuestas', '---', 1, '2026-02-02 06:58:28', '2026-02-02 06:58:28'),
(14, 'Preventis', '', 1, '2026-02-02 06:58:28', '2026-02-02 06:58:28'),
(15, 'Emprende360', '---', 1, '2026-02-02 06:58:28', '2026-02-02 06:58:28'),
(16, 'Delivery', '', 1, '2026-02-02 06:58:28', '2026-02-02 06:58:28'),
(17, 'CYPHER', '', 1, '2026-02-02 06:58:28', '2026-02-02 06:58:28'),
(18, 'Administracion', 'Personal del equipo administrativo', 1, '2026-02-02 06:58:28', '2026-02-02 06:58:28'),
(19, 'Prestamos de Alivio', '', 1, '2026-02-02 06:58:28', '2026-02-02 06:58:28'),
(20, 'Tu Jugada RD', '', 1, '2026-02-02 06:58:28', '2026-02-02 06:58:28'),
(21, 'Operaciones', '', 1, '2026-02-02 06:58:28', '2026-02-02 06:58:28'),
(22, 'Recursos Humanos', '', 1, '2026-02-02 06:58:28', '2026-02-02 06:58:28');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `client_campaigns`
--

CREATE TABLE `client_campaigns` (
  `client_id` int(11) NOT NULL,
  `campaign_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `client_campaigns`
--

INSERT INTO `client_campaigns` (`client_id`, `campaign_id`, `created_at`) VALUES
(1, 1, '2026-01-25 15:09:12');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `client_portal_settings`
--

CREATE TABLE `client_portal_settings` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `show_calls` tinyint(1) DEFAULT '1',
  `show_evaluations` tinyint(1) DEFAULT '1',
  `show_ai_summary` tinyint(1) DEFAULT '0',
  `show_recordings` tinyint(1) DEFAULT '0',
  `show_agent_scores` tinyint(1) DEFAULT '1',
  `metrics_json` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `client_portal_settings`
--

INSERT INTO `client_portal_settings` (`id`, `client_id`, `show_calls`, `show_evaluations`, `show_ai_summary`, `show_recordings`, `show_agent_scores`, `metrics_json`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, 0, 1, 1, '[\"total_calls\",\"avg_score\",\"compliance_rate\",\"critical_fails\",\"top_agent\"]', '2026-01-25 15:09:12', '2026-01-25 15:09:12');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `corporate_clients`
--

CREATE TABLE `corporate_clients` (
  `id` int(11) NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `industry` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_email` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `corporate_clients`
--

INSERT INTO `corporate_clients` (`id`, `name`, `industry`, `contact_name`, `contact_email`, `active`, `created_at`, `updated_at`) VALUES
(1, 'Jonathan Sandoval', 'TECNOLOGIA', '8495024061', 'jonathansandovalferreira@gmail.com', 1, '2026-01-25 15:09:12', '2026-01-25 15:09:12');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `evaluations`
--

CREATE TABLE `evaluations` (
  `id` int(11) NOT NULL,
  `call_id` int(11) DEFAULT NULL,
  `agent_id` int(11) NOT NULL,
  `qa_id` int(11) NOT NULL,
  `campaign_id` int(11) NOT NULL,
  `form_template_id` int(11) NOT NULL,
  `call_date` date DEFAULT NULL,
  `call_duration` int(11) DEFAULT NULL COMMENT 'Duration in seconds',
  `total_score` decimal(5,2) DEFAULT NULL,
  `max_possible_score` decimal(5,2) DEFAULT NULL,
  `percentage` decimal(5,2) DEFAULT NULL,
  `general_comments` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `feedback_confirmed` tinyint(1) DEFAULT '0',
  `feedback_confirmed_at` datetime DEFAULT NULL,
  `feedback_evidence_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `feedback_evidence_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `feedback_evidence_note` text COLLATE utf8mb4_unicode_ci,
  `action_type` enum('feedback','call_evaluation') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `improvement_areas` text COLLATE utf8mb4_unicode_ci,
  `improvement_plan` text COLLATE utf8mb4_unicode_ci,
  `tasks_commitments` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `evaluations`
--

INSERT INTO `evaluations` (`id`, `call_id`, `agent_id`, `qa_id`, `campaign_id`, `form_template_id`, `call_date`, `call_duration`, `total_score`, `max_possible_score`, `percentage`, `general_comments`, `created_at`, `feedback_confirmed`, `feedback_confirmed_at`, `feedback_evidence_path`, `feedback_evidence_name`, `feedback_evidence_note`, `action_type`, `improvement_areas`, `improvement_plan`, `tasks_commitments`) VALUES
(1, NULL, 2, 1, 1, 4, '2026-01-22', NULL, 999.99, 999.99, 90.00, 'Test evaluation via Jetski', '2026-01-23 03:10:20', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(2, 1, 2, 1, 1, 4, '2026-01-22', 560, 999.99, 999.99, 80.00, '', '2026-01-26 10:34:45', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(3, NULL, 5, 4, 1, 4, '2026-01-26', NULL, 999.99, 999.99, 33.00, '', '2026-01-26 18:31:21', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(4, 3, 5, 4, 1, 3, '2026-01-26', 879, 0.00, 999.99, 0.00, '', '2026-01-26 19:42:52', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(5, 15, 65, 69, 16, 5, '2026-02-02', 95, 999.99, 999.99, 100.00, 'La agente mostró un tono de voz adecuado y profesional durante toda la llamada, saludó al cliente, solicitó su número de teléfono para tomar la orden, pidió permiso por el tiempo de espera y validó correctamente los detalles de la orden, incluyendo el nombre del cliente, el método de pago y el tiempo de espera. Además, mostró amabilidad al despedirse y ofreció una experiencia cordial. Como sugerencia, sería recomendable preguntar al cliente si desea desechables, aunque no es obligatorio. En general, la agente demostró un excelente desempeño, con solo pequeños ajustes que podrían mejorar aún más la atención brindada.', '2026-02-02 16:29:42', 0, NULL, NULL, NULL, '', 'call_evaluation', 'Pregunta sobre desechables: Aunque no es obligatorio, sería beneficioso preguntar si el cliente desea desechables para completar su pedido, especialmente en casos de delivery.\r\n\r\nProactividad en la oferta de productos adicionales: Asegurarse de ofrecer siempre productos adicionales como postres, entradas u otros, para maximizar las oportunidades de venta.', 'Incorporar la pregunta sobre desechables: Incluir esta consulta en el guion o recordatorio para que sea parte del proceso estándar de toma de orden, sin que dependa únicamente de la iniciativa de la agente.\r\n\r\nReforzar la oferta de productos adicionales: Recordar a la agente la importancia de ofrecer productos adicionales de manera natural durante la llamada, siguiendo el guion, para evitar que se pase por alto.\r\n\r\nEntrenamiento continuo en comunicación efectiva: Aunque la agente ya tiene un buen tono de voz, es útil seguir reforzando las técnicas de comunicación activa, escucha y cómo mantener una interacción fluida y eficiente con el cliente.', 'Añadir la pregunta sobre desechables al guion de llamadas y hacer un recordatorio visual para las agentes durante sus turnos, de manera que esta consulta se vuelva parte de la rutina.\r\n\r\nEntrenamiento sobre la oferta de productos adicionales: Realizar una breve capacitación sobre cómo ofrecer productos adicionales de forma efectiva y natural sin interrumpir el flujo de la llamada.\r\n\r\nEvaluación continua: Monitorear las próximas llamadas para verificar la implementación de estas mejoras y proporcionar retroalimentación adicional si es necesario.');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `evaluation_answers`
--

CREATE TABLE `evaluation_answers` (
  `id` int(11) NOT NULL,
  `evaluation_id` int(11) NOT NULL,
  `field_id` int(11) NOT NULL,
  `score_given` decimal(5,2) DEFAULT NULL,
  `text_answer` text COLLATE utf8mb4_unicode_ci,
  `comment` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `evaluation_answers`
--

INSERT INTO `evaluation_answers` (`id`, `evaluation_id`, `field_id`, `score_given`, `text_answer`, `comment`) VALUES
(1, 1, 7, 0.00, NULL, ''),
(2, 1, 8, 90.00, NULL, ''),
(3, 1, 9, 0.00, NULL, ''),
(4, 2, 7, 0.00, NULL, ''),
(5, 2, 8, 80.00, NULL, ''),
(6, 2, 9, 0.00, NULL, 'BIEN'),
(7, 3, 7, 0.00, NULL, ''),
(8, 3, 8, 33.00, NULL, ''),
(9, 3, 9, 0.00, NULL, ''),
(10, 4, 28, 0.00, NULL, ''),
(11, 4, 29, 0.00, NULL, ''),
(12, 4, 30, 0.00, NULL, ''),
(13, 4, 31, 0.00, NULL, ''),
(14, 4, 32, 0.00, NULL, ''),
(15, 4, 33, 0.00, NULL, ''),
(16, 5, 34, 100.00, NULL, ''),
(17, 5, 35, 100.00, NULL, ''),
(18, 5, 36, 100.00, NULL, ''),
(19, 5, 37, 100.00, NULL, ''),
(20, 5, 38, 100.00, NULL, ''),
(21, 5, 39, 100.00, NULL, '');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `form_fields`
--

CREATE TABLE `form_fields` (
  `id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL,
  `label` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_type` enum('score','text','yes_no','select') COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` text COLLATE utf8mb4_unicode_ci COMMENT 'JSON array for select options',
  `max_score` int(11) DEFAULT '10',
  `weight` decimal(5,2) DEFAULT '1.00' COMMENT 'Weight for scoring calculation',
  `field_order` int(11) DEFAULT '0',
  `required` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `form_fields`
--

INSERT INTO `form_fields` (`id`, `template_id`, `label`, `field_type`, `options`, `max_score`, `weight`, `field_order`, `required`, `created_at`) VALUES
(1, 1, 'Razon de contacto', 'select', NULL, 100, 50.00, 0, 1, '2026-01-23 02:08:55'),
(2, 1, 'Amabilidad', 'score', NULL, 100, 50.00, 1, 1, '2026-01-23 02:08:55'),
(3, 2, 'Razon de contacto', 'select', NULL, 100, 50.00, 0, 1, '2026-01-23 02:10:46'),
(4, 2, 'Amabilidad', 'score', NULL, 100, 50.00, 1, 1, '2026-01-23 02:10:46'),
(7, 4, 'Razon de contacto', 'select', '', 100, 40.00, 0, 1, '2026-01-23 02:16:51'),
(8, 4, 'Amabilidad', 'score', '', 100, 40.00, 1, 1, '2026-01-23 02:16:51'),
(9, 4, 'Canal', 'select', 'Chat, Email, Telefono', 100, 20.00, 2, 1, '2026-01-23 02:16:51'),
(28, 3, 'Presenta la encuesta de forma clara y cordial. Solicita autorización al cliente. Maneja objeciones o agradece si el cliente no desea participar.', 'yes_no', '', 100, 10.00, 0, 1, '2026-01-26 19:42:21'),
(29, 3, 'Agradece al cliente por aceptar. Continúa con preguntas claras, sin sugerir respuestas, mantiene tono neutral y explica cuando es necesario.', 'yes_no', '', 100, 40.00, 1, 1, '2026-01-26 19:42:21'),
(30, 3, 'Registra respuestas tal como las indica el cliente. No opina sobre respuestas. Maneja el tiempo sin afectar calidad y explica preguntas cuando es necesario.', 'yes_no', '', 100, 60.00, 2, 1, '2026-01-26 19:42:21'),
(31, 3, 'Demuestra dominio del tema. Responde objeciones correctamente. Realiza todas las preguntas obligatorias. Proporciona solo la información permitida', 'yes_no', '', 100, 80.00, 3, 1, '2026-01-26 19:42:21'),
(32, 3, 'Realiza todas las preguntas finales, se despide cordialmente y agradece el tiempo del cliente.', 'yes_no', '', 100, 90.00, 4, 1, '2026-01-26 19:42:21'),
(33, 3, 'Culmina correctamente todas las preguntas. Registra respuestas con autenticidad y realiza disposición correcta de la llamada.', 'yes_no', '', 100, 100.00, 5, 1, '2026-01-26 19:42:21'),
(34, 5, 'El agente inicia la llamada con un saludo cordial y profesional, se presenta con su nombre y el del restaurante, muestra disposición para ayudar y solicita de forma clara el número telefónico del clie', 'yes_no', '', 100, 16.00, 0, 1, '2026-02-02 15:29:47'),
(35, 5, 'El agente verifica si el cliente está registrado; de ser así, confirma el nombre y valida si el número tiene WhatsApp. Si no está registrado, recopila nombre y apellido y confirma el medio de contacto', 'yes_no', '', 100, 16.00, 1, 1, '2026-02-02 15:29:47'),
(36, 5, 'El agente pregunta si el cliente desea comprobante y toma la orden de forma clara y ordenada, escuchando activamente y confirmando cada producto solicitado.', 'yes_no', '', 100, 16.00, 2, 1, '2026-02-02 15:29:47'),
(37, 5, 'El agente ofrece productos adicionales, repite la orden para validación, informa el monto total, confirma el método de pago, comunica el tiempo estimado de entrega o recogida e indica que la orden no ', 'yes_no', '', 100, 16.00, 3, 1, '2026-02-02 15:29:47'),
(38, 5, 'El agente finaliza la llamada agradeciendo al cliente, mencionando el nombre del restaurante, despidiéndose cordialmente y asegurándose de que el cliente no tenga dudas antes de colgar.', 'yes_no', '', 100, 16.00, 4, 1, '2026-02-02 15:29:47'),
(39, 5, 'El agente sigue todas las etapas del proceso sin omisiones, respetando el script, manteniendo un tono profesional y asegurando una correcta gestión de la orden según los estándares de calidad.', 'yes_no', '', 100, 16.00, 5, 1, '2026-02-02 15:29:47');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `form_templates`
--

CREATE TABLE `form_templates` (
  `id` int(11) NOT NULL,
  `campaign_id` int(11) NOT NULL,
  `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `form_templates`
--

INSERT INTO `form_templates` (`id`, `campaign_id`, `title`, `description`, `active`, `created_at`, `updated_at`) VALUES
(1, 1, 'Evaluación de Calidad 2026', '', 0, '2026-01-23 02:08:55', '2026-01-23 02:10:46'),
(2, 1, 'Evaluación de Calidad 2026', '', 0, '2026-01-23 02:10:46', '2026-01-23 07:22:30'),
(3, 1, 'Evaluación de Calidad Encuentas 2026', '', 1, '2026-01-23 02:12:28', '2026-01-26 19:42:21'),
(4, 1, 'Evaluación de Calidad 2026', '', 0, '2026-01-23 02:16:51', '2026-01-26 19:31:31'),
(5, 16, 'Evaluación de Calidad 2026', '', 1, '2026-02-02 15:29:47', '2026-02-02 15:29:47');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `qa_permissions`
--

CREATE TABLE `qa_permissions` (
  `id` int(11) NOT NULL,
  `can_view_users` tinyint(1) DEFAULT '0',
  `can_create_users` tinyint(1) DEFAULT '0',
  `can_view_clients` tinyint(1) DEFAULT '0',
  `can_manage_clients` tinyint(1) DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `qa_permissions`
--

INSERT INTO `qa_permissions` (`id`, `can_view_users`, `can_create_users`, `can_view_clients`, `can_manage_clients`, `created_at`, `updated_at`) VALUES
(1, 0, 0, 0, 0, '2026-02-02 07:32:26', '2026-02-02 07:32:26');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `training_exams`
--

CREATE TABLE `training_exams` (
  `id` int(11) NOT NULL,
  `agent_id` int(11) NOT NULL,
  `qa_id` int(11) NOT NULL,
  `campaign_id` int(11) DEFAULT NULL,
  `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('draft','assigned','in_progress','completed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'assigned',
  `total_score` decimal(6,2) DEFAULT NULL,
  `max_score` decimal(6,2) DEFAULT NULL,
  `percentage` decimal(6,2) DEFAULT NULL,
  `ai_summary` text COLLATE utf8mb4_unicode_ci,
  `prompt_context` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  `public_token` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_enabled` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `training_exams`
--

INSERT INTO `training_exams` (`id`, `agent_id`, `qa_id`, `campaign_id`, `title`, `status`, `total_score`, `max_score`, `percentage`, `ai_summary`, `prompt_context`, `created_at`, `updated_at`, `completed_at`, `public_token`, `public_enabled`) VALUES
(1, 2, 1, 1, 'Examen de Refuerzo: Ventas Inbound - Agente Test Agent', 'in_progress', NULL, NULL, NULL, NULL, 'Razon de contacto (0.0), Canal (0.0), Amabilidad (90.0)', '2026-01-26 09:56:46', '2026-01-26 10:09:05', NULL, 'a7bd80467343fac4876f8056788a6ba0affe1c6c', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `training_exam_answers`
--

CREATE TABLE `training_exam_answers` (
  `id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `answer_text` text COLLATE utf8mb4_unicode_ci,
  `score` decimal(5,2) DEFAULT NULL,
  `feedback` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `training_exam_questions`
--

CREATE TABLE `training_exam_questions` (
  `id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `question_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `question_type` enum('mcq','open') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `options_json` text COLLATE utf8mb4_unicode_ci,
  `correct_answer` text COLLATE utf8mb4_unicode_ci,
  `weight` decimal(5,2) DEFAULT '1.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `training_exam_questions`
--

INSERT INTO `training_exam_questions` (`id`, `exam_id`, `question_text`, `question_type`, `options_json`, `correct_answer`, `weight`, `created_at`) VALUES
(1, 1, '¿Cuál es el objetivo principal de identificar correctamente la \'Razón de Contacto\' en una llamada de ventas inbound?', 'mcq', '[\"Reducir el tiempo de la llamada únicamente\",\"Categorizar la necesidad del cliente para ofrecer el producto adecuado y mejorar la analítica\",\"Evitar hablar con el cliente sobre sus necesidades\",\"Cumplir con un requisito administrativo sin impacto en la venta\"]', 'Categorizar la necesidad del cliente para ofrecer el producto adecuado y mejorar la analítica', 10.00, '2026-01-26 09:56:46'),
(2, 1, 'Si un cliente llama preguntando por el precio de un paquete específico, ¿cuál es la \'Razón de Contacto\' más precisa?', 'mcq', '[\"Soporte técnico\",\"Consulta de facturación\",\"Solicitud de información comercial\",\"Reclamo por servicio\"]', 'Solicitud de información comercial', 10.00, '2026-01-26 09:56:46'),
(3, 1, 'Explique con sus propias palabras por qué es vital para la campaña de Ventas Inbound distinguir el \'Canal\' por el cual ingresa el cliente.', 'open', NULL, 'El agente debe mencionar que el canal define el contexto del cliente, la procedencia del lead y permite medir la efectividad de las estrategias de marketing.', 10.00, '2026-01-26 09:56:46'),
(4, 1, 'En el contexto de Ventas Inbound, ¿qué define al \'Canal\' de comunicación?', 'mcq', '[\"El estado de ánimo del agente\",\"El medio físico o digital a través del cual el cliente contacta a la empresa\",\"La marca del teléfono que usa el cliente\",\"El horario en el que se realiza la llamada\"]', 'El medio físico o digital a través del cual el cliente contacta a la empresa', 10.00, '2026-01-26 09:56:46'),
(5, 1, 'Durante la tipificación, el agente olvida marcar la \'Razón de Contacto\'. ¿Qué impacto tiene esto en la campaña?', 'mcq', '[\"Ninguno, lo importante es vender\",\"Mejora la velocidad de atención\",\"Se pierde visibilidad sobre qué productos interesan más a los clientes y se ensucian las métricas\",\"El sistema lo corrige automáticamente\"]', 'Se pierde visibilidad sobre qué productos interesan más a los clientes y se ensucian las métricas', 10.00, '2026-01-26 09:56:46'),
(6, 1, '¿Cómo puede un agente demostrar \'Amabilidad\' (su fortaleza actual) mientras indaga sobre la \'Razón de Contacto\'?', 'open', NULL, 'Utilizando un tono empático, escuchando activamente sin interrumpir y validando la necesidad del cliente antes de ofrecer una solución.', 10.00, '2026-01-26 09:56:46'),
(7, 1, 'Un cliente contacta por el chat de ventas pero pide ser llamado. ¿Cuál es el canal de origen que debe prevalecer en el registro inicial?', 'mcq', '[\"Teléfono\",\"Chat\",\"Correo electrónico\",\"Presencial\"]', 'Chat', 10.00, '2026-01-26 09:56:46'),
(8, 1, '¿Cuál de las siguientes acciones asegura una correcta identificación de la Razón de Contacto?', 'mcq', '[\"Interrumpir al cliente para adivinar qué quiere\",\"Realizar preguntas de sondeo abiertas al inicio de la interacción\",\"Asumir que todos llaman por lo mismo\",\"Esperar a que el cliente mencione una palabra clave sin preguntar\"]', 'Realizar preguntas de sondeo abiertas al inicio de la interacción', 10.00, '2026-01-26 09:56:46'),
(9, 1, 'Describa el procedimiento correcto si un cliente contacta por un canal equivocado (ej. llama a ventas para un reclamo técnico).', 'open', NULL, 'Se debe atender con amabilidad, registrar la razón de contacto como \'Error de canal/Derivación\' y transferir al área correspondiente siguiendo el protocolo.', 10.00, '2026-01-26 09:56:46'),
(10, 1, 'Si la métrica de \'Razón de Contacto\' está en 0.0, ¿qué comportamiento debe cambiar el agente inmediatamente?', 'mcq', '[\"Ser más amable\",\"Hablar más rápido\",\"Asegurarse de seleccionar la opción correcta en el CRM antes de finalizar cada interacción\",\"No pedir los datos del cliente\"]', 'Asegurarse de seleccionar la opción correcta en el CRM antes de finalizar cada interacción', 10.00, '2026-01-26 09:56:46');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `training_notifications`
--

CREATE TABLE `training_notifications` (
  `id` int(11) NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `agent_id` int(11) DEFAULT NULL,
  `qa_id` int(11) DEFAULT NULL,
  `status` enum('pending','sent','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `payload_json` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `training_notifications`
--

INSERT INTO `training_notifications` (`id`, `type`, `agent_id`, `qa_id`, `status`, `payload_json`, `created_at`, `sent_at`) VALUES
(1, 'roleplay_completed', 2, 4, 'pending', '{\"roleplay_id\":\"2\",\"score\":null}', '2026-01-26 18:27:23', NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `training_roleplays`
--

CREATE TABLE `training_roleplays` (
  `id` int(11) NOT NULL,
  `script_id` int(11) DEFAULT NULL,
  `agent_id` int(11) NOT NULL,
  `qa_id` int(11) DEFAULT NULL,
  `campaign_id` int(11) DEFAULT NULL,
  `status` enum('active','completed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `score` decimal(5,2) DEFAULT NULL,
  `ai_summary` text COLLATE utf8mb4_unicode_ci,
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `objectives_text` text COLLATE utf8mb4_unicode_ci,
  `tone_text` text COLLATE utf8mb4_unicode_ci,
  `obstacles_text` text COLLATE utf8mb4_unicode_ci,
  `rubric_id` int(11) DEFAULT NULL,
  `ai_actions_json` text COLLATE utf8mb4_unicode_ci,
  `qa_plan_text` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `training_roleplays`
--

INSERT INTO `training_roleplays` (`id`, `script_id`, `agent_id`, `qa_id`, `campaign_id`, `status`, `score`, `ai_summary`, `started_at`, `ended_at`, `created_at`, `updated_at`, `objectives_text`, `tone_text`, `obstacles_text`, `rubric_id`, `ai_actions_json`, `qa_plan_text`) VALUES
(1, 1, 2, 1, 1, 'active', NULL, NULL, '2026-01-25 23:35:09', NULL, '2026-01-26 10:35:09', '2026-01-26 10:35:09', NULL, NULL, NULL, NULL, NULL, NULL),
(2, 1, 2, 4, 1, 'completed', NULL, NULL, '2026-01-26 13:26:42', '2026-01-26 13:27:23', '2026-01-26 18:26:42', '2026-01-26 18:27:23', NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `training_roleplay_coach_notes`
--

CREATE TABLE `training_roleplay_coach_notes` (
  `id` int(11) NOT NULL,
  `roleplay_id` int(11) NOT NULL,
  `qa_id` int(11) NOT NULL,
  `note_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `training_roleplay_feedback`
--

CREATE TABLE `training_roleplay_feedback` (
  `id` int(11) NOT NULL,
  `roleplay_id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `feedback` text COLLATE utf8mb4_unicode_ci,
  `checklist_json` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `qa_score` decimal(5,2) DEFAULT NULL,
  `qa_feedback` text COLLATE utf8mb4_unicode_ci,
  `qa_checklist_json` text COLLATE utf8mb4_unicode_ci,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `training_roleplay_feedback`
--

INSERT INTO `training_roleplay_feedback` (`id`, `roleplay_id`, `message_id`, `score`, `feedback`, `checklist_json`, `created_at`, `qa_score`, `qa_feedback`, `qa_checklist_json`, `approved_by`, `approved_at`) VALUES
(1, 1, 2, 5.00, 'El agente no cumplió con el protocolo establecido para el escenario. El saludo fue excesivamente informal y no profesional, omitió la identificación personal y de la empresa, no verificó datos ni abordó el motivo de la llamada (consolidación de deudas). Además, no hubo manejo de objeciones ni cierre con solicitud de referidos.', '[{\"item\":\"saludo\",\"status\":\"deficiente\"},{\"item\":\"identificacion\",\"status\":\"no cumplido\"},{\"item\":\"verificacion\",\"status\":\"no cumplido\"},{\"item\":\"empatia\",\"status\":\"no cumplido\"},{\"item\":\"resolucion\",\"status\":\"no cumplido\"},{\"item\":\"cierre\",\"status\":\"no cumplido\"}]', '2026-01-26 10:41:34', NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `training_roleplay_messages`
--

CREATE TABLE `training_roleplay_messages` (
  `id` int(11) NOT NULL,
  `roleplay_id` int(11) NOT NULL,
  `sender` enum('agent','ai','qa') COLLATE utf8mb4_unicode_ci NOT NULL,
  `message_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `training_roleplay_messages`
--

INSERT INTO `training_roleplay_messages` (`id`, `roleplay_id`, `sender`, `message_text`, `created_at`) VALUES
(1, 1, 'qa', 'Hola luis, como te sientes', '2026-01-26 10:39:24'),
(2, 1, 'qa', 'Hola luis, como te sientes', '2026-01-26 10:41:25'),
(3, 1, 'ai', 'Hola, bien gracias. Mire, si me llama por lo de la consolidación de deudas, ya conozco cómo funciona el programa y no me interesa ahora mismo.', '2026-01-26 10:41:34');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `training_rubrics`
--

CREATE TABLE `training_rubrics` (
  `id` int(11) NOT NULL,
  `campaign_id` int(11) DEFAULT NULL,
  `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` tinyint(1) DEFAULT '1',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `training_rubrics`
--

INSERT INTO `training_rubrics` (`id`, `campaign_id`, `title`, `active`, `created_by`, `created_at`) VALUES
(1, NULL, 'Hola', 1, 4, '2026-01-26 18:26:29');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `training_rubric_items`
--

CREATE TABLE `training_rubric_items` (
  `id` int(11) NOT NULL,
  `rubric_id` int(11) NOT NULL,
  `label` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `weight` decimal(5,2) DEFAULT '1.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `training_rubric_items`
--

INSERT INTO `training_rubric_items` (`id`, `rubric_id`, `label`, `weight`, `created_at`) VALUES
(1, 1, 'Hola, Hola', 1.00, '2026-01-26 18:26:29');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `training_scripts`
--

CREATE TABLE `training_scripts` (
  `id` int(11) NOT NULL,
  `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `script_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `scenario_text` text COLLATE utf8mb4_unicode_ci,
  `persona_json` text COLLATE utf8mb4_unicode_ci,
  `source_type` enum('best_call','upload','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `call_id` int(11) DEFAULT NULL,
  `campaign_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_filename` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `training_scripts`
--

INSERT INTO `training_scripts` (`id`, `title`, `script_text`, `scenario_text`, `persona_json`, `source_type`, `call_id`, `campaign_id`, `created_by`, `file_path`, `original_filename`, `active`, `created_at`, `updated_at`) VALUES
(1, 'Manejo de Objeciones y Cierre con Referidos en Consolidación de Deudas', 'Array', 'Un cliente potencial que completó un formulario en línea para consolidar deudas es contactado por un agente. El cliente muestra desinterés inmediato alegando que ya conoce el programa, lo que requiere que el agente intente rebatir la objeción de forma breve y, ante la negativa final, cierre la llamada solicitando referidos.', 'Luis, un usuario que busca opciones financieras pero se siente saturado por la información o ya ha tenido contacto previo con servicios similares. Es directo y no desea invertir mucho tiempo en la llamada.', 'best_call', 1, 1, 1, NULL, NULL, 1, '2026-01-26 10:35:01', '2026-01-26 10:35:01');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','qa','agent','client') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'agent',
  `client_id` int(11) DEFAULT NULL,
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `source` enum('quality','ponche') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'quality',
  `external_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `users`
--

INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name`, `role`, `client_id`, `active`, `created_at`, `updated_at`, `source`, `external_id`) VALUES
(1, 'admin', '$2y$10$0xzPe4aJ7vCpdAIt1IVyhuMeU92gdy3EsY4IfCsGxu3iWtFxrSlSC', 'Administrator', 'admin', NULL, 1, '2026-01-23 01:37:45', '2026-01-23 01:38:57', 'quality', NULL),
(2, 'testagent', '$2y$10$JFXSXome8bvGN2gb6JMDpeTUMWZMIar6XjJlfLIeAib6ex9UsV8de', 'Test Agent', 'agent', NULL, 1, '2026-01-23 01:51:51', '2026-01-23 01:51:51', 'quality', NULL),
(3, 'jsandoval', '$2y$10$PwVOfN2xzFkMKAilSex5B.ayXfImHkBJX3euJvU/83osSrIhRzSZi', 'Jonathan Sandoval Ferreira', 'client', 1, 1, '2026-01-25 15:09:12', '2026-01-25 15:09:12', 'quality', NULL),
(4, 'hhidalgo', '$2y$10$rXIE/L00yKAdlVhJ2deque1JAiSLb7hHeHNkOh21Afo7IunMGi4O6', 'Hugo Hidalgo', 'admin', NULL, 1, '2026-01-26 16:45:30', '2026-01-26 16:45:30', 'quality', NULL),
(5, 'EmelyR', '$2y$10$HmRYuUM6zZUPk.IH4/TMk.KEqVhma7Fj1HS79B/4NfNRZ9Q1t80iG', 'Emely Maria Rodriguez Sosa', 'agent', NULL, 1, '2026-01-26 17:31:27', '2026-01-26 17:31:27', 'quality', NULL),
(6, 'yameiky.evallish', '$2y$10$KgDsi/Nx5Uoc4hE.r68qj.yTobnHKOfBNPmx27TtEAxkvvIESADVy', 'Yameiky Alexandra Jose Villa Fana', 'agent', NULL, 1, '2026-02-02 16:06:57', '2026-02-02 16:06:57', 'ponche', 7),
(7, 'kismel.evallish', '$2y$10$o5Bt8xooc3SxIZPLAX45K.gEETU84p8fuI2B/ujOnvrM3o9/4FCI2', 'Kismel Fraideline Quezada Peguero', 'agent', NULL, 0, '2026-02-02 16:06:57', '2026-02-02 16:06:57', 'ponche', 8),
(8, 'yokasta.evallish', '$2y$10$TxA.uEVNhUyl800QewDn.eQOgWtg4om9P4IC8l5up1HaqSOsnwSbK', 'Yokasta Payano', 'agent', NULL, 0, '2026-02-02 16:06:57', '2026-02-02 16:06:57', 'ponche', 9),
(9, 'sadelyn.evallish', '$2y$10$YxaG0Djv.e9.eGFD0N92WeB7hPK6d1uqzWKPP5cdLrWP0M2mQlnBC', 'Sadelyn García Infante', 'agent', NULL, 1, '2026-02-02 16:06:57', '2026-02-02 16:06:57', 'ponche', 10),
(10, 'ashley.evallish', '$2y$10$ZwNY7JiSWTBVwBdrjD5Q8.NdtBgyC.eTkt1xQlsYITvkG7a4oQ9rS', 'Ashley Estevez Castillo', 'agent', NULL, 0, '2026-02-02 16:06:58', '2026-02-02 16:06:58', 'ponche', 11),
(11, 'agentdemo', '$2y$10$ydFCzZmyDnW7eiIe6PQ/nekAoidaRsO9ITy8H2SyOR4gLIQyoDu5W', 'Agente DEMO', 'agent', NULL, 1, '2026-02-02 16:06:58', '2026-02-02 16:06:58', 'ponche', 14),
(12, 'francheskar.evallish', '$2y$10$3L6a5W5ij5vbhgcGvEUUku/tLaLDGON8n1F//IS8cU.9GTkyk9PJK', 'Francheska Michelle Rodriguez Toribio', 'agent', NULL, 1, '2026-02-02 16:06:58', '2026-02-02 16:06:58', 'ponche', 15),
(13, 'yunielyp.evallish', '$2y$10$qcMvJTynziwFmL3pKO9mt.u5NsOVBLRlJh0o7vttbyVX4lW87L6RS', 'Yuniely Chamell Peralta Grullon', 'agent', NULL, 1, '2026-02-02 16:06:58', '2026-02-02 16:06:58', 'ponche', 17),
(14, 'yenaurisg.evallish', '$2y$10$2T/tF4B59AQtFHef6ViLc.LtruVD6h8lxDWbLopPJLRb/.Yj8eWwi', 'Yenauris Pillar Garcia Frias', 'agent', NULL, 0, '2026-02-02 16:06:58', '2026-02-02 16:06:58', 'ponche', 18),
(15, 'joannyu.evallish', '$2y$10$3uf1708kvMCqfup27X4yzOnRYGdc5QgBP6xa2yYmRZUG7o3/T02su', 'Joanny Anabel Ureña Sosa', 'agent', NULL, 0, '2026-02-02 16:06:58', '2026-02-02 16:06:58', 'ponche', 19),
(16, 'arturoj.evallish', '$2y$10$kEssfvwrLOjPbs7kUfnivu6d5IOFoqFFZ51bQ1iRVIj2qa8nn3DBC', 'Arturo Ramon Jimenez Marcelino', 'agent', NULL, 1, '2026-02-02 16:06:59', '2026-02-02 16:06:59', 'ponche', 20),
(17, 'mayerlit.evallish', '$2y$10$wxZKGVqHCqqRB3C6nJhciuzSLjmvdRkfjU9UXf55pN9LTGf9wi8N.', 'Mayerli Claret Tineo Colon', 'agent', NULL, 1, '2026-02-02 16:06:59', '2026-02-02 16:06:59', 'ponche', 21),
(18, 'danllelyg.evallish', '$2y$10$d6bBOQ7elVyBI.xspnJ3JuoKFLQnLjzDrDTAcynQ1BuHn/buwxdEy', 'Danllely Jose Gonzalez', 'agent', NULL, 1, '2026-02-02 16:06:59', '2026-02-02 16:06:59', 'ponche', 22),
(19, 'felixn.evallish', '$2y$10$pUbWkzuOGEp61Zuno7ywGevsHDo7sfaxlfOfHEJNji4Vamn3UWptC', 'Felix Eduardo Nuñez Taveras', 'agent', NULL, 0, '2026-02-02 16:06:59', '2026-02-02 16:06:59', 'ponche', 23),
(20, 'joelc.evallish', '$2y$10$pByYLMHO5RcecgVk1FoNYO924lBxB3H8W/Cx96cuEHLBxQKUXNkLm', 'Joel Rafael Chala Garcia', 'agent', NULL, 1, '2026-02-02 16:06:59', '2026-02-02 16:06:59', 'ponche', 24),
(21, 'elainyg.evallish', '$2y$10$fmbVQz87O73iiejWx5fG/.EOjC1/MZbxMm7RunxcbiX7wDJYHkr6a', 'Elainy Lisbeth Guzman Mercado', 'agent', NULL, 0, '2026-02-02 16:07:00', '2026-02-02 16:07:00', 'ponche', 25),
(22, 'honeymarm.evallish', '$2y$10$kiz18xhOa803gN2sxXNuf.7oCBCE1UiKCYPJxAP/eCmrkGedr/FUO', 'Honeymar Martínez Polanco', 'agent', NULL, 0, '2026-02-02 16:07:00', '2026-02-02 16:07:00', 'ponche', 26),
(23, 'marielyss.evallish', '$2y$10$xPemA8NxhEryKmKNKb1Eoe8w0lCSGgy3fgUTh4GcjRMDQFF0SM1Yi', 'Marielys Michelle Sánchez de los Santos', 'agent', NULL, 0, '2026-02-02 16:07:00', '2026-02-02 16:07:00', 'ponche', 27),
(24, 'jeremyb.evallish', '$2y$10$hRjAGzUZv9ood1Sk1Alr/.oNcLi/fnOKeLcXSMsL0/GE1qi2UT4JO', 'Jeremy Ball Vásquez', 'agent', NULL, 0, '2026-02-02 16:07:00', '2026-02-02 16:07:00', 'ponche', 28),
(25, 'yeflyf.evallish', '$2y$10$DV/h6vasZ56p5X3D/6XDpe.JCDNoy/mwOE8XA9Y1FTaN1aFjzVinW', 'Jeffly Bernardin Francois', 'agent', NULL, 0, '2026-02-02 16:07:00', '2026-02-02 16:07:00', 'ponche', 30),
(26, 'duvensonh.evallish', '$2y$10$AL3Ctw5q.pgst/5R8cTQIuu.gIXYoyf1L0DK9cvh17XTOLf.ik/Y.', 'Duvenson Henry', 'agent', NULL, 0, '2026-02-02 16:07:01', '2026-02-02 16:07:01', 'ponche', 31),
(27, 'ambarv.evallish', '$2y$10$/h43X/7WlftpYstgM9i3q.UFq/9YzeMVBXW75kMGS6OYz7jd6UxAe', 'Ambar Nayeli Veras', 'agent', NULL, 0, '2026-02-02 16:07:01', '2026-02-02 16:07:01', 'ponche', 32),
(28, 'amberlyg.evallish', '$2y$10$BCQA7MJR4fokvFb1ocN1u.JJe9lbFqiZHwfxvORicx5YhuZEXq69m', 'Amberly Nicolle Guzman Polanco', 'agent', NULL, 1, '2026-02-02 16:07:01', '2026-02-02 16:07:01', 'ponche', 33),
(29, 'yanneryc.evallish', '$2y$10$Jb6dwLdhbW9bi1Bl2EKFUOYYLPVPEM4kyqoJIREDwBWZ2GzKez8HC', 'Yannery Cabreja Morel', 'agent', NULL, 1, '2026-02-02 16:07:01', '2026-02-02 16:07:01', 'ponche', 35),
(30, 'ashleyt.evallish', '$2y$10$sm0MxDaB77Yj6LpFQG7LcOjKo6AVwMCjhLsDzupEq301hphuXi9Ea', 'Ashley Gabriela Torres Pichardo', 'agent', NULL, 0, '2026-02-02 16:07:01', '2026-02-02 16:07:01', 'ponche', 36),
(31, 'zoel.evallish', '$2y$10$HtidRax0MK7Z81ig8XADRe7XKFrIihe1ZeBYbP/gmdx8iOTf86dIK', 'Zoe Laire Cruz', 'agent', NULL, 1, '2026-02-02 16:07:01', '2026-02-02 16:07:01', 'ponche', 37),
(32, 'joseh.evallish', '$2y$10$GjcZRrZRh7LB6O8fiL5sdeDq/2sQTb1mGSH5iOlkRVZTpbJtVGaEe', 'Jose Manuel Hilario Montero', 'agent', NULL, 1, '2026-02-02 16:07:02', '2026-02-02 16:07:02', 'ponche', 38),
(33, 'crisr.evallish', '$2y$10$gdaIl/OJHTsZkfoQs2WZYeiTw0XXm2m2VLjYXnd8iPXkbPOEXB4Fu', 'Cris Elena Rojas Frias', 'agent', NULL, 1, '2026-02-02 16:07:02', '2026-02-02 16:07:02', 'ponche', 39),
(34, 'jeremysm.evallish', '$2y$10$0BDbvwJLHq6/R9syh3bshuyU8.yQljtaOaawxq0eX5WygmR1E82uu', 'Jeremy Samuel Marte Rodriguez', 'agent', NULL, 1, '2026-02-02 16:07:02', '2026-02-02 16:07:02', 'ponche', 40),
(35, 'pablon.evallish', '$2y$10$JGXwWidQg.g3XuDLRcsW5eFLg0oVuvKLf6iGUvdss56mwz8JygH5S', 'Pablo Michael Nuñez Suero', 'agent', NULL, 0, '2026-02-02 16:07:02', '2026-02-02 16:07:02', 'ponche', 41),
(36, 'albertom.evallish', '$2y$10$BpdP0R1nEAndJ8tgDozmc.EZshKyFSG8i8tgB5aP3w0hJvQIiDA9O', 'Alberto Antonio Mora Peña', 'agent', NULL, 0, '2026-02-02 16:07:02', '2026-02-02 16:07:02', 'ponche', 42),
(37, 'pamelar.evallish', '$2y$10$P4XqWEpMMZhUqXmjQh/ZTu/kksZrxUjKRFFITZJn1MY4mliUcsHBy', 'Pamela Lisbeth Rivas Tavarez', 'agent', NULL, 0, '2026-02-02 16:07:03', '2026-02-02 16:07:03', 'ponche', 43),
(38, 'danielw.evallish', '$2y$10$ydmNvnZKWlBMsvgUHAsc1eb2ObBsh4UNjeqsFva12oIDoJfdIFIQO', 'Daniel Joseph Williams', 'agent', NULL, 0, '2026-02-02 16:07:03', '2026-02-02 16:07:03', 'ponche', 44),
(39, 'argenysr.evallish', '$2y$10$dR9Q0wEXVDD5q5WoXTayUO5/y3HIe2Z.84Qf4r0QheHHmj4jVgCOm', 'Argenis Mariano Rosa', 'qa', NULL, 0, '2026-02-02 16:07:03', '2026-02-02 16:07:03', 'ponche', 45),
(40, 'carolynr.evallish', '$2y$10$JgbcWzJMGDbok.lwq0NnwevPkMmKT2fRApvjiwVTJLFeVJBaFoKNy', 'Carolyn Rojas Vargas', 'agent', NULL, 0, '2026-02-02 16:07:03', '2026-02-02 16:07:03', 'ponche', 46),
(41, 'lewisa.evallish', '$2y$10$vUa.iLkbnZXsv/er.hm14OnMqucw.FXs7nMB4xsqLlMpmKYWd8niS', 'Lewis Steven Aguilera Ureña', 'agent', NULL, 0, '2026-02-02 16:07:03', '2026-02-02 16:07:03', 'ponche', 50),
(42, 'dawinr.evallish', '$2y$10$q/w.3dXQ19XoEwXX..Y7C.HdRWPXjh/WG7yJKYrWuoSJHmOPema62', 'Dawin Emanuel Rodriguez Almengo', 'agent', NULL, 0, '2026-02-02 16:07:04', '2026-02-02 16:07:04', 'ponche', 51),
(43, 'cesarv.evallish', '$2y$10$QRAA8U3ZQT3WaaIi/XOclu0eJ/3Q/wUjm90G8yIviKDXaftVs1NTW', 'Cesar Misael Victoria Sarante', 'agent', NULL, 0, '2026-02-02 16:07:04', '2026-02-02 16:07:04', 'ponche', 52),
(44, 'christyf.evallish', '$2y$10$bTY..5DfGkOiH1bJ6ZROse2aivepnL4RmjWZkjGz9GXvRTz1FQ7FK', 'Christy Farah', 'agent', NULL, 1, '2026-02-02 16:07:04', '2026-02-02 16:07:04', 'ponche', 53),
(45, 'anaip.evallish', '$2y$10$V5Yc2WPtzmi6h6wD3pQXsudD1vVeai13snDQe8Kt2fEkUUlqUcY3y', 'Ana Elsa Inoa Peguero', 'agent', NULL, 0, '2026-02-02 16:07:04', '2026-02-02 16:07:04', 'ponche', 54),
(46, 'pedrof.evallish', '$2y$10$n5t4Mjn0lWXCPNhfdgU.WukHWgwUiLEB1.dQVPQk6ud2X1JZtBNf6', 'Pedro Francisco Fadul Jorge', 'agent', NULL, 0, '2026-02-02 16:07:04', '2026-02-02 16:07:04', 'ponche', 55),
(47, 'francisf.evallish', '$2y$10$laRVDlD97LKlIFPDuiJKuepUWXs6gl4myF/EYZmFdzequ0Xumvd9O', 'Francis Margarita Fernandez Toribio', 'agent', NULL, 0, '2026-02-02 16:07:04', '2026-02-02 16:07:04', 'ponche', 63),
(48, 'crismeilyr.evallish', '$2y$10$NQoHMnNJmPuzt.lG/5TOfO0b/50GmE8YxNf.944BFuNfovOslKuVW', 'Crismeily Paola Rosario Martinez', 'agent', NULL, 1, '2026-02-02 16:07:05', '2026-02-02 16:07:05', 'ponche', 64),
(49, 'franciscog.evallish', '$2y$10$hQ1uKXha7VLFN0GOJKZF6OagZ.1jl7IBo5fmud6QTNLLgu9pw8B8a', 'Francisco Antonio Gonel Torres', 'agent', NULL, 0, '2026-02-02 16:07:05', '2026-02-02 16:07:05', 'ponche', 65),
(50, 'juannye.evallish', '$2y$10$A8Mkl1O00Ju4wh8K8DvSueWYY2igDPp9FWvA5FtL61VpNaykVPR12', 'Juanny Esther de Jesus', 'agent', NULL, 0, '2026-02-02 16:07:05', '2026-02-02 16:07:05', 'ponche', 66),
(51, 'miguel.evallish', '$2y$10$WYbjgM8l787xrd74aPsfvOy3u7h/6N3dwk1Mio4pxSjzuDo/2laea', 'Miguel Alfonso Martínez', 'agent', NULL, 1, '2026-02-02 16:07:05', '2026-02-02 16:07:05', 'ponche', 67),
(52, 'yessica.evallish', '$2y$10$Evnurzd73iDutSpFmUgvp.aaD6cmLRrBMJ1boAsHjeX87q3zHnBjK', 'Yessica Carolina Santos Acosta', 'agent', NULL, 0, '2026-02-02 16:07:05', '2026-02-02 16:07:05', 'ponche', 68),
(53, 'Tahina.evallish', '$2y$10$EzGLYsMpTb/MbCMcGkrFZ.yGvDbPQYcc9R4jqB6cRsztb6.muWeba', 'Tahina Indira Jiménez Peña', 'agent', NULL, 0, '2026-02-02 16:07:06', '2026-02-02 16:07:06', 'ponche', 69),
(54, 'Oliver.Evallish', '$2y$10$FacVwg2BRzS3nbEM3hYZxO0aD2PSCnIpY.s2G/YQ/eIJty.drdWpG', 'Oliver santiago Peña González', 'agent', NULL, 0, '2026-02-02 16:07:06', '2026-02-02 16:07:06', 'ponche', 70),
(55, 'Elvis.Evallish', '$2y$10$DS4B1T6J7vOyOpvRXjll6uGjpS13S09HeN4h2zBzDnN.xJ/0.IXiy', 'Elvis Joel Rojas Núñez', 'agent', NULL, 1, '2026-02-02 16:07:06', '2026-02-02 16:07:06', 'ponche', 71),
(56, 'Ricardo.Evallish', '$2y$10$qnIpUNtOm3rsa9lI649kFu6/IUkN391Kjj.bwdnV0C1zxIV98UJwK', 'Ricardo Reynaldo Peña Guerrero', 'agent', NULL, 1, '2026-02-02 16:07:06', '2026-02-02 16:07:06', 'ponche', 72),
(57, 'Genesis.Evallish', '$2y$10$zWQwaWuzqK73gQ.G2unm3.Ac5XeBc/ptbVdNNsSGl1nXPpwqAeRjC', 'Genesis Rosario Martinez', 'agent', NULL, 1, '2026-02-02 16:07:06', '2026-02-02 16:07:06', 'ponche', 73),
(58, 'Acevedo.Evallish', '$2y$10$AC6IavSG8YE3nDjZzt84kuxGGEKNO88zbYMWyyxWZTVllATqR8mGa', 'Miguel Arcángel Acevedo Santos', 'agent', NULL, 0, '2026-02-02 16:07:07', '2026-02-02 16:07:07', 'ponche', 74),
(59, 'Katherin.Evallish', '$2y$10$S1so82VupQ.c3.DVCZegrOF0S01nnC35soFBrLPn/SI1g.BTl1GNW', 'Katherin Esmeralda Domínguez García', 'agent', NULL, 1, '2026-02-02 16:07:07', '2026-02-02 16:07:07', 'ponche', 75),
(60, 'Railin.Evallish', '$2y$10$wpP9c.4zUgyQhuPQ0hLUIefZWGlYEhONb0h79f4GkO.dgdC.ZRBUi', 'Railin López Ozoria', 'agent', NULL, 0, '2026-02-02 16:07:07', '2026-02-02 16:07:07', 'ponche', 76),
(61, 'Rowss.Evallish', '$2y$10$ynHIkTgPejZ2C6DB9O/gJuHpuH6C6o/4MUVpG78San7lX/s2o0D.a', 'Rowss Paola Diaz Tejada', 'agent', NULL, 1, '2026-02-02 16:07:07', '2026-02-02 16:07:07', 'ponche', 77),
(62, 'America.Evallish', '$2y$10$dnHHChYfzxev0SfYKRjOn.NsQvVwZkWM3GNpVF8vSMSPy2SFu7KOu', 'America Mayerlyn Rodriguez De Sroczynski', 'agent', NULL, 1, '2026-02-02 16:07:07', '2026-02-02 16:07:07', 'ponche', 78),
(63, 'E. Martinez.Evallish', '$2y$10$1x9/Ouamh1lNEdkALta7.uepMzgY9Y.G61Tlsbakb8OFWRjwJdZly', 'Elvis Martínez Feliz', 'agent', NULL, 0, '2026-02-02 16:07:07', '2026-02-02 16:07:07', 'ponche', 79),
(64, 'Ana.Evallish', '$2y$10$ZDULFyjtgQVhznj0DKeLYOTcmcqWGyVVfFumfJy..zHtMX6CuLq3.', 'Ana Crystal Ovalles Henríquez', 'agent', NULL, 1, '2026-02-02 16:07:08', '2026-02-02 16:07:08', 'ponche', 80),
(65, 'Brendaly.Evallish', '$2y$10$a/0Dd6dJidx6z/vFOYISlOXqYxR2zGle4VF0wPHu8o3..UdXO8xNq', 'Brendaly Esther Vasquez Peña', 'agent', NULL, 1, '2026-02-02 16:07:08', '2026-02-02 16:07:08', 'ponche', 81),
(66, 'Yaribel.Evallish', '$2y$10$temajNPMXj2ZmqAjppWxcOjURCRVZbswPGZ5DyyJVUhPjX4SRhnWq', 'Yaribel Parra Corniel', 'agent', NULL, 0, '2026-02-02 16:07:08', '2026-02-02 16:07:08', 'ponche', 82),
(67, 'Gabriel.Evallish', '$2y$10$CH4wqVT23hIu90LSW12XgeHxmlbacNu3jMvWDfDPsM5PTMV2Lz.LK', 'Gabriel Fernando Ureña De La Cruz', 'agent', NULL, 0, '2026-02-02 16:07:08', '2026-02-02 16:07:08', 'ponche', 83),
(68, 'hugo', '$2y$10$nnmwoDv4sr5qnHWtGkpYyOmPcDrn6mwkuzzmkyQdl.o0omrLBCq2y', 'Hugo Hidalgo', 'admin', NULL, 1, '2026-02-02 16:21:44', '2026-02-02 16:21:44', 'ponche', 16),
(69, 'emelyr.evallish', '$2y$10$41VDCAFdkefVANh7fL6HrOjpDuX4KKKZaED0WpE2Ctz5tqoIKwhAq', 'Emely Maria Rodriguez Sosa', 'admin', NULL, 1, '2026-02-02 16:23:35', '2026-02-02 16:23:35', 'ponche', 29);

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `ai_evaluation_criteria`
--
ALTER TABLE `ai_evaluation_criteria`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `campaign_id` (`campaign_id`);

--
-- Indices de la tabla `calls`
--
ALTER TABLE `calls`
  ADD PRIMARY KEY (`id`),
  ADD KEY `agent_id` (`agent_id`),
  ADD KEY `campaign_id` (`campaign_id`);

--
-- Indices de la tabla `call_ai_analytics`
--
ALTER TABLE `call_ai_analytics`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_call_model` (`call_id`,`model`);

--
-- Indices de la tabla `campaigns`
--
ALTER TABLE `campaigns`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `client_campaigns`
--
ALTER TABLE `client_campaigns`
  ADD PRIMARY KEY (`client_id`,`campaign_id`),
  ADD KEY `campaign_id` (`campaign_id`);

--
-- Indices de la tabla `client_portal_settings`
--
ALTER TABLE `client_portal_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `client_id` (`client_id`);

--
-- Indices de la tabla `corporate_clients`
--
ALTER TABLE `corporate_clients`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `evaluations`
--
ALTER TABLE `evaluations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `agent_id` (`agent_id`),
  ADD KEY `qa_id` (`qa_id`),
  ADD KEY `campaign_id` (`campaign_id`),
  ADD KEY `form_template_id` (`form_template_id`),
  ADD KEY `fk_evaluations_call_id` (`call_id`);

--
-- Indices de la tabla `evaluation_answers`
--
ALTER TABLE `evaluation_answers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `evaluation_id` (`evaluation_id`),
  ADD KEY `field_id` (`field_id`);

--
-- Indices de la tabla `form_fields`
--
ALTER TABLE `form_fields`
  ADD PRIMARY KEY (`id`),
  ADD KEY `template_id` (`template_id`);

--
-- Indices de la tabla `form_templates`
--
ALTER TABLE `form_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `campaign_id` (`campaign_id`);

--
-- Indices de la tabla `qa_permissions`
--
ALTER TABLE `qa_permissions`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `training_exams`
--
ALTER TABLE `training_exams`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_training_exam_token` (`public_token`),
  ADD KEY `agent_id` (`agent_id`),
  ADD KEY `qa_id` (`qa_id`),
  ADD KEY `campaign_id` (`campaign_id`);

--
-- Indices de la tabla `training_exam_answers`
--
ALTER TABLE `training_exam_answers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `question_id` (`question_id`);

--
-- Indices de la tabla `training_exam_questions`
--
ALTER TABLE `training_exam_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `exam_id` (`exam_id`);

--
-- Indices de la tabla `training_notifications`
--
ALTER TABLE `training_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `agent_id` (`agent_id`),
  ADD KEY `qa_id` (`qa_id`);

--
-- Indices de la tabla `training_roleplays`
--
ALTER TABLE `training_roleplays`
  ADD PRIMARY KEY (`id`),
  ADD KEY `script_id` (`script_id`),
  ADD KEY `agent_id` (`agent_id`),
  ADD KEY `qa_id` (`qa_id`),
  ADD KEY `campaign_id` (`campaign_id`),
  ADD KEY `fk_training_roleplays_rubric` (`rubric_id`);

--
-- Indices de la tabla `training_roleplay_coach_notes`
--
ALTER TABLE `training_roleplay_coach_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `roleplay_id` (`roleplay_id`),
  ADD KEY `qa_id` (`qa_id`);

--
-- Indices de la tabla `training_roleplay_feedback`
--
ALTER TABLE `training_roleplay_feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `roleplay_id` (`roleplay_id`),
  ADD KEY `message_id` (`message_id`);

--
-- Indices de la tabla `training_roleplay_messages`
--
ALTER TABLE `training_roleplay_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `roleplay_id` (`roleplay_id`);

--
-- Indices de la tabla `training_rubrics`
--
ALTER TABLE `training_rubrics`
  ADD PRIMARY KEY (`id`),
  ADD KEY `campaign_id` (`campaign_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indices de la tabla `training_rubric_items`
--
ALTER TABLE `training_rubric_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `rubric_id` (`rubric_id`);

--
-- Indices de la tabla `training_scripts`
--
ALTER TABLE `training_scripts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `call_id` (`call_id`),
  ADD KEY `campaign_id` (`campaign_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indices de la tabla `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `uidx_users_source_external` (`source`,`external_id`),
  ADD KEY `fk_users_client` (`client_id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `ai_evaluation_criteria`
--
ALTER TABLE `ai_evaluation_criteria`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `calls`
--
ALTER TABLE `calls`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT de la tabla `call_ai_analytics`
--
ALTER TABLE `call_ai_analytics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `campaigns`
--
ALTER TABLE `campaigns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT de la tabla `client_portal_settings`
--
ALTER TABLE `client_portal_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `corporate_clients`
--
ALTER TABLE `corporate_clients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `evaluations`
--
ALTER TABLE `evaluations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `evaluation_answers`
--
ALTER TABLE `evaluation_answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT de la tabla `form_fields`
--
ALTER TABLE `form_fields`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT de la tabla `form_templates`
--
ALTER TABLE `form_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `qa_permissions`
--
ALTER TABLE `qa_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `training_exams`
--
ALTER TABLE `training_exams`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `training_exam_answers`
--
ALTER TABLE `training_exam_answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `training_exam_questions`
--
ALTER TABLE `training_exam_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de la tabla `training_notifications`
--
ALTER TABLE `training_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `training_roleplays`
--
ALTER TABLE `training_roleplays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `training_roleplay_coach_notes`
--
ALTER TABLE `training_roleplay_coach_notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `training_roleplay_feedback`
--
ALTER TABLE `training_roleplay_feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `training_roleplay_messages`
--
ALTER TABLE `training_roleplay_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `training_rubrics`
--
ALTER TABLE `training_rubrics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `training_rubric_items`
--
ALTER TABLE `training_rubric_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `training_scripts`
--
ALTER TABLE `training_scripts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=70;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `ai_evaluation_criteria`
--
ALTER TABLE `ai_evaluation_criteria`
  ADD CONSTRAINT `ai_evaluation_criteria_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `corporate_clients` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `ai_evaluation_criteria_ibfk_2` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `calls`
--
ALTER TABLE `calls`
  ADD CONSTRAINT `calls_ibfk_1` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `calls_ibfk_2` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`);

--
-- Filtros para la tabla `call_ai_analytics`
--
ALTER TABLE `call_ai_analytics`
  ADD CONSTRAINT `call_ai_analytics_ibfk_1` FOREIGN KEY (`call_id`) REFERENCES `calls` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `client_campaigns`
--
ALTER TABLE `client_campaigns`
  ADD CONSTRAINT `client_campaigns_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `corporate_clients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `client_campaigns_ibfk_2` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `client_portal_settings`
--
ALTER TABLE `client_portal_settings`
  ADD CONSTRAINT `client_portal_settings_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `corporate_clients` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `evaluations`
--
ALTER TABLE `evaluations`
  ADD CONSTRAINT `evaluations_ibfk_1` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `evaluations_ibfk_2` FOREIGN KEY (`qa_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `evaluations_ibfk_3` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`),
  ADD CONSTRAINT `evaluations_ibfk_4` FOREIGN KEY (`form_template_id`) REFERENCES `form_templates` (`id`),
  ADD CONSTRAINT `fk_evaluations_call_id` FOREIGN KEY (`call_id`) REFERENCES `calls` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `evaluation_answers`
--
ALTER TABLE `evaluation_answers`
  ADD CONSTRAINT `evaluation_answers_ibfk_1` FOREIGN KEY (`evaluation_id`) REFERENCES `evaluations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `evaluation_answers_ibfk_2` FOREIGN KEY (`field_id`) REFERENCES `form_fields` (`id`);

--
-- Filtros para la tabla `form_fields`
--
ALTER TABLE `form_fields`
  ADD CONSTRAINT `form_fields_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `form_templates` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `form_templates`
--
ALTER TABLE `form_templates`
  ADD CONSTRAINT `form_templates_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `training_exams`
--
ALTER TABLE `training_exams`
  ADD CONSTRAINT `training_exams_ibfk_1` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `training_exams_ibfk_2` FOREIGN KEY (`qa_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `training_exams_ibfk_3` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `training_exam_answers`
--
ALTER TABLE `training_exam_answers`
  ADD CONSTRAINT `training_exam_answers_ibfk_1` FOREIGN KEY (`question_id`) REFERENCES `training_exam_questions` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `training_exam_questions`
--
ALTER TABLE `training_exam_questions`
  ADD CONSTRAINT `training_exam_questions_ibfk_1` FOREIGN KEY (`exam_id`) REFERENCES `training_exams` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `training_notifications`
--
ALTER TABLE `training_notifications`
  ADD CONSTRAINT `training_notifications_ibfk_1` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `training_notifications_ibfk_2` FOREIGN KEY (`qa_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `training_roleplays`
--
ALTER TABLE `training_roleplays`
  ADD CONSTRAINT `fk_training_roleplays_rubric` FOREIGN KEY (`rubric_id`) REFERENCES `training_rubrics` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `training_roleplays_ibfk_1` FOREIGN KEY (`script_id`) REFERENCES `training_scripts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `training_roleplays_ibfk_2` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `training_roleplays_ibfk_3` FOREIGN KEY (`qa_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `training_roleplays_ibfk_4` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `training_roleplay_coach_notes`
--
ALTER TABLE `training_roleplay_coach_notes`
  ADD CONSTRAINT `training_roleplay_coach_notes_ibfk_1` FOREIGN KEY (`roleplay_id`) REFERENCES `training_roleplays` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `training_roleplay_coach_notes_ibfk_2` FOREIGN KEY (`qa_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `training_roleplay_feedback`
--
ALTER TABLE `training_roleplay_feedback`
  ADD CONSTRAINT `training_roleplay_feedback_ibfk_1` FOREIGN KEY (`roleplay_id`) REFERENCES `training_roleplays` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `training_roleplay_feedback_ibfk_2` FOREIGN KEY (`message_id`) REFERENCES `training_roleplay_messages` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `training_roleplay_messages`
--
ALTER TABLE `training_roleplay_messages`
  ADD CONSTRAINT `training_roleplay_messages_ibfk_1` FOREIGN KEY (`roleplay_id`) REFERENCES `training_roleplays` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `training_rubrics`
--
ALTER TABLE `training_rubrics`
  ADD CONSTRAINT `training_rubrics_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `training_rubrics_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `training_rubric_items`
--
ALTER TABLE `training_rubric_items`
  ADD CONSTRAINT `training_rubric_items_ibfk_1` FOREIGN KEY (`rubric_id`) REFERENCES `training_rubrics` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `training_scripts`
--
ALTER TABLE `training_scripts`
  ADD CONSTRAINT `training_scripts_ibfk_1` FOREIGN KEY (`call_id`) REFERENCES `calls` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `training_scripts_ibfk_2` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `training_scripts_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_client` FOREIGN KEY (`client_id`) REFERENCES `corporate_clients` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
