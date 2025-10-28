    </main>
    <footer class="site-footer mt-16 border-t border-slate-800/70 bg-slate-900/80 backdrop-blur-lg">
        <div class="max-w-7xl mx-auto px-6 py-10">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 text-sm text-slate-300">
                <div>
                    <h3 class="footer-title text-lg font-semibold">Evallish BPO</h3>
                    <p class="footer-text mt-3 text-balance">
                        Plataforma centralizada para registrar asistencia, visualizar productividad y compartir informes confiables con todo el equipo.
                    </p>
                </div>
                <div>
                    <h3 class="footer-title text-lg font-semibold">Enlaces rapidos</h3>
                    <ul class="mt-3 space-y-2 footer-links">
                        <li><a href="dashboard.php" class="inline-flex items-center gap-2 transition-colors"><i class="fas fa-gauge text-xs"></i><span>Dashboard</span></a></li>
                        <li><a href="records.php" class="inline-flex items-center gap-2 transition-colors"><i class="fas fa-table text-xs"></i><span>Records</span></a></li>
                        <li><a href="settings.php" class="inline-flex items-center gap-2 transition-colors"><i class="fas fa-sliders-h text-xs"></i><span>Configuracion</span></a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="footer-title text-lg font-semibold">Necesitas ayuda</h3>
                    <ul class="mt-3 space-y-2 footer-text">
                        <li class="flex items-center gap-2"><i class="fas fa-envelope text-xs"></i><span>support@evallishbpo.com</span></li>
                        <li class="flex items-center gap-2"><i class="fas fa-life-ring text-xs"></i><span>Documentacion interna</span></li>
                    </ul>
                </div>
            </div>
            <div class="mt-10 flex flex-col md:flex-row md:items-center md:justify-between gap-3 text-xs footer-text-muted">
                <p>&copy; <?= date('Y') ?> Evallish BPO. Todos los derechos reservados.</p>
                <p>Evallish BPO Suite</p>
            </div>
        </div>
    </footer>

    <script>
    const scrollButton = document.createElement('button');
    scrollButton.innerHTML = '<i class="fas fa-arrow-up"></i>';
    scrollButton.className = 'fixed bottom-6 right-6 bg-cyan-500 text-slate-900 p-3 rounded-full shadow-lg shadow-cyan-500/35 hover:bg-cyan-400 transition-all duration-200 opacity-0 pointer-events-none';
    document.body.appendChild(scrollButton);

    window.addEventListener('scroll', () => {
        if (window.pageYOffset > 280) {
            scrollButton.classList.remove('opacity-0', 'pointer-events-none');
            scrollButton.classList.add('opacity-100');
        } else {
            scrollButton.classList.add('opacity-0', 'pointer-events-none');
            scrollButton.classList.remove('opacity-100');
        }
    });

    scrollButton.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
    </script>
    </body>
    </html>
