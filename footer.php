<?php
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    http_response_code(403);
    exit;
}
$layout = $layout ?? 'public';
?>
<?php if ($layout === 'app'): ?>
            </main>
            <footer class="app-footer">AGREE · Agricultural marketplace for Ibaan, Batangas</footer>
        </div>
    </div>
<?php else: ?>
    </main>
    <footer class="site-footer">
        <div class="container footer-grid">
            <div>
                <div class="brand footer-brand">
                    <span class="brand-mark"><i class="fa-solid fa-seedling"></i></span>
                    <strong>AGREE</strong>
                </div>
                <p>A marketplace for farm cooperatives in Ibaan and the kitchens and stores that buy from them.</p>
            </div>
            <div>
                <h2>How payment works</h2>
                <p>A cooperative shows a permit before it can sell. The buyer pays a deposit first. The rest is paid in cash when the order arrives.</p>
            </div>
            <div>
                <h2>Seller checks</h2>
                <p>Cooperatives upload a CDA certificate or mayor's permit. This follows the Internet Transactions Act, Republic Act No. 11967.</p>
            </div>
        </div>
        <div class="container footer-base">
            <span>Ibaan, Batangas, Philippines</span>
            <span>&copy; <?= date('Y') ?> AGREE capstone marketplace</span>
        </div>
    </footer>
<?php endif; ?>
<?php if (!empty($needsChart)): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<?php endif; ?>
<script src="agree.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
