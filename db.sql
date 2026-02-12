Create table Grammar_Mentor_Email

-- Struktur für Tabelle `subscribers`
CREATE TABLE `subscribers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `subscribed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE subscriptions (
    id                    BIGINT AUTO_INCREMENT PRIMARY KEY,
    email                 VARCHAR(255) NOT NULL UNIQUE,
    plan                  ENUM('free', 'pro', 'lifetime') DEFAULT 'free',
    status                ENUM('free', 'active', 'cancelled', 'expired', 'past_due') DEFAULT 'free',
    valid_until           DATE NULL,
    lemon_customer_id     BIGINT NULL,
    lemon_subscription_id BIGINT NULL,
    lemon_order_id        BIGINT NULL,
    created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);