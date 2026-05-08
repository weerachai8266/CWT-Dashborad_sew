<?php

const APP_VERSION_LABEL  = 'v2.2 [beta]';
const APP_COMPANY_LABEL  = 'Chaiwattana Tannery Group';
const APP_BUSINESS_LABEL = 'Leather Seats and Auto Parts';
const APP_POWERED_BY     = 'weerachai';

// Allowed origins for CORS — internal network only
const CORS_ALLOWED_ORIGINS = ['http://192.168.100.10', 'http://192.168.0.44'];

function setCorsHeaders(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, CORS_ALLOWED_ORIGINS, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function validateDate(string $date, string $format = 'Y-m-d'): bool {
    $d = DateTime::createFromFormat($format, $date);
    return $d !== false && $d->format($format) === $date;
}

function renderDashboardFooter(string $page_label): void {
    $page = htmlspecialchars($page_label, ENT_QUOTES, 'UTF-8');
    $company = htmlspecialchars(APP_COMPANY_LABEL, ENT_QUOTES, 'UTF-8');
    $business = htmlspecialchars(APP_BUSINESS_LABEL, ENT_QUOTES, 'UTF-8');
    $version = htmlspecialchars(APP_VERSION_LABEL, ENT_QUOTES, 'UTF-8');
    $powered_by = htmlspecialchars(APP_POWERED_BY, ENT_QUOTES, 'UTF-8');
    ?>
            <footer class="dashboard-footer">
                <div class="footer-inner">
                    <div class="footer-left">
                        <span class="footer-brand"><?= $company ?></span>
                        <span><?= $business ?></span>
                    </div>
                    <div class="footer-center">
                        <span><?= $page ?> &copy; 2025&ndash;<span id="copyright-year"></span></span>
                    </div>
                    <div class="footer-right">
                        <span class="footer-version"><?= $version ?></span>
                        <span class="footer-divider">|</span>
                        <span>Powered by <?= $powered_by ?></span>
                    </div>
                </div>
            </footer>
    <?php
}
