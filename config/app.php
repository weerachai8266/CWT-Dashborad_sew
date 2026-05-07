<?php

const APP_VERSION_LABEL = 'v2.2 [beta]';
const APP_COMPANY_LABEL = 'Chaiwattana Tannery Group';
const APP_BUSINESS_LABEL = 'Leather Seats and Auto Parts';
const APP_POWERED_BY = 'weerachai';

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
