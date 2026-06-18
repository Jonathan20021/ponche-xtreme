    </main>
    <footer class="site-footer">
        <div class="max-w-7xl mx-auto px-6 py-9">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 text-sm">
                <div>
                    <h3 class="footer-title text-base font-bold mb-3">Evallish BPO</h3>
                    <p class="footer-text text-balance">
                        Plataforma centralizada para registrar asistencia, visualizar productividad y compartir informes confiables con todo el equipo.
                    </p>
                </div>
                <div>
                    <h3 class="footer-title text-base font-bold mb-3">Enlaces rápidos</h3>
                    <ul class="space-y-2 footer-links">
                        <li><a href="dashboard.php" class="inline-flex items-center gap-2 transition-colors"><i class="fas fa-gauge text-xs w-4"></i><span>Panel de Control</span></a></li>
                        <li><a href="records.php" class="inline-flex items-center gap-2 transition-colors"><i class="fas fa-table text-xs w-4"></i><span>Registros</span></a></li>
                        <li><a href="settings.php" class="inline-flex items-center gap-2 transition-colors"><i class="fas fa-sliders-h text-xs w-4"></i><span>Configuración</span></a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="footer-title text-base font-bold mb-3">¿Necesitas ayuda?</h3>
                    <ul class="space-y-2 footer-text">
                        <li class="flex items-center gap-2"><i class="fas fa-envelope text-xs w-4"></i><span>support@evallishbpo.com</span></li>
                        <li class="flex items-center gap-2"><i class="fas fa-life-ring text-xs w-4"></i><span>Documentación interna</span></li>
                    </ul>
                </div>
            </div>
            <div class="mt-8 pt-6 flex flex-col md:flex-row md:items-center md:justify-between gap-3 text-xs footer-text-muted" style="border-top:1px solid var(--border);">
                <p>&copy; <?= date('Y') ?> Evallish BPO. Todos los derechos reservados.</p>
                <p class="footer-text-muted">Evallish BPO Control Suite</p>
            </div>
        </div>
    </footer>

    <script>
    (function () {
        const scrollButton = document.createElement('button');
        scrollButton.type = 'button';
        scrollButton.setAttribute('aria-label', 'Volver arriba');
        scrollButton.innerHTML = '<i class="fas fa-arrow-up"></i>';
        scrollButton.className = 'fixed bottom-6 right-6 p-3 rounded-full transition-all duration-200 opacity-0 pointer-events-none';
        scrollButton.style.zIndex = '40';
        scrollButton.style.width = '46px';
        scrollButton.style.height = '46px';
        document.body.appendChild(scrollButton);

        window.addEventListener('scroll', () => {
            if (window.pageYOffset > 280) {
                scrollButton.classList.remove('opacity-0', 'pointer-events-none');
                scrollButton.classList.add('opacity-100');
            } else {
                scrollButton.classList.add('opacity-0', 'pointer-events-none');
                scrollButton.classList.remove('opacity-100');
            }
        }, { passive: true });

        scrollButton.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    })();
    </script>
    </body>
    </html>
