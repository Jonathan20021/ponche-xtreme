<!-- Footer -->
<footer class="bg-gray-800 text-white mt-12">
    <div class="container mx-auto px-4 py-8">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <!-- Company Info -->
            <div>
                <h3 class="text-lg font-semibold mb-4">Attendance System</h3>
                <p class="text-gray-400">
                    Track employee attendance, breaks, and work hours efficiently with our comprehensive system.
                </p>
            </div>

            <!-- Quick Links -->
            <div>
                <h3 class="text-lg font-semibold mb-4">Quick Links</h3>
                <ul class="space-y-2">
                    <li>
                        <a href="index.php" class="text-gray-400 hover:text-white transition-colors duration-200">
                            <i class="fas fa-home mr-2"></i>Home
                        </a>
                    </li>
                    <li>
                        <a href="records.php" class="text-gray-400 hover:text-white transition-colors duration-200">
                            <i class="fas fa-clipboard-list mr-2"></i>Records
                        </a>
                    </li>
                    <li>
                        <a href="profile.php" class="text-gray-400 hover:text-white transition-colors duration-200">
                            <i class="fas fa-user mr-2"></i>Profile
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Contact Info -->
            <div>
                <h3 class="text-lg font-semibold mb-4">Contact</h3>
                <ul class="space-y-2">
                    <li class="text-gray-400">
                        <i class="fas fa-envelope mr-2"></i>support@attendance.com
                    </li>
                    <li class="text-gray-400">
                        <i class="fas fa-phone mr-2"></i>+1 (555) 123-4567
                    </li>
                </ul>
            </div>
        </div>

        <!-- Copyright -->
        <div class="border-t border-gray-700 mt-8 pt-6 text-center text-gray-400">
            <p>&copy; <?= date('Y') ?> Attendance System. All rights reserved.</p>
        </div>
    </div>
</footer>

<!-- Additional Scripts -->
<script>
// Scroll to top button
const scrollButton = document.createElement('button');
scrollButton.innerHTML = '<i class="fas fa-arrow-up"></i>';
scrollButton.className = 'fixed bottom-8 right-8 bg-indigo-600 text-white p-3 rounded-full shadow-lg hover:bg-indigo-700 transition-colors duration-200 opacity-0 invisible';
document.body.appendChild(scrollButton);

window.addEventListener('scroll', () => {
    if (window.pageYOffset > 300) {
        scrollButton.classList.remove('opacity-0', 'invisible');
        scrollButton.classList.add('opacity-100', 'visible');
    } else {
        scrollButton.classList.add('opacity-0', 'invisible');
        scrollButton.classList.remove('opacity-100', 'visible');
    }
});

scrollButton.addEventListener('click', () => {
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
});
</script>
</body>
</html> 