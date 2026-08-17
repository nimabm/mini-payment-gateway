-- Defaults for the operator-editable settings. Every one of these is
-- changeable from Settings in the admin panel.
INSERT INTO settings (key, value, updated_at) VALUES
    ('panel.locale',            'fa',                 '2024-01-01 00:00:00'),
    ('panel.calendar',          'jalali',             '2024-01-01 00:00:00'),
    ('panel.timezone',          'Asia/Tehran',        '2024-01-01 00:00:00'),
    ('panel.page_size',         '25',                 '2024-01-01 00:00:00'),
    ('gateways.force_sandbox',  '0',                  '2024-01-01 00:00:00'),
    ('checkout.brand_name',     'Payment Gateway',    '2024-01-01 00:00:00');
