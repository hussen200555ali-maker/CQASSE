<?php
/**
 * ============================================================
 * EQAS - Footer Template
 * نظام التقييم الشامل - القالب السفلي
 * ============================================================
 */
?>
            </main><!-- /.main-content-inner -->

            <!-- Footer -->
            <footer class="footer">
                <p>
                    © <?php echo date('Y'); ?> نظام التقييم الشامل لجودة التعليم المدرسي  (EQAS)
                    — جميع الحقوق محفوظة
                </p>
            </footer>

        </div><!-- /.main-content -->
    </div><!-- /.page-wrapper -->

    <!-- ============================================================
         MODAL CONTAINER - Dynamic Modals
         ============================================================ -->
    <div id="modal-container">
        <!-- Confirm Delete Modal -->
        <div class="modal-overlay" id="modal-confirm-delete">
            <div class="modal-dialog modal-sm">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title">تأكيد الحذف</h3>
                        <button class="modal-close" type="button" aria-label="إغلاق">✕</button>
                    </div>
                    <div class="modal-body text-center">
                        <div style="font-size:48px; margin-bottom:16px;">⚠️</div>
                        <p id="confirm-delete-message">هل أنت متأكد من الحذف؟ لا يمكن التراجع عن هذه العملية.</p>
                    </div>
                    <div class="modal-footer">
                        <form id="confirm-delete-form" method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                            <button type="submit" class="btn btn-danger">نعم، حذف</button>
                        </form>
                        <button type="button" class="btn btn-light" onclick="closeModal('modal-confirm-delete')">إلغاء</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         TOAST CONTAINER
         ============================================================ -->
    <div class="toast-container" id="toast-container"></div>

    <!-- ============================================================
         SCROLL TO TOP BUTTON
         ============================================================ -->
    <button class="scroll-to-top" id="scrollToTop" aria-label="العودة للأعلى" title="العودة للأعلى">
        <i class="fas fa-chevron-up"></i>
    </button>

    <!-- ============================================================
         JAVASCRIPT INCLUDES
         ============================================================ -->

    <!-- Main Application JS -->
    <script src="<?php echo EQAS_ASSETS_URL ?? 'assets'; ?>/js/main.js"></script>

    <!-- Page-specific JS variable -->
    <?php if (isset($pageJs)): ?>
    <script>
        // Page-specific initialization
        document.addEventListener('DOMContentLoaded', function() {
            <?php echo $pageJs; ?>
        });
    </script>
    <?php endif; ?>

    <!-- Page-specific JS file -->
    <?php if (isset($pageJsFile)): ?>
    <script src="<?php echo htmlspecialchars($pageJsFile); ?>"></script>
    <?php endif; ?>

</body>
</html>
