function initUiScripts() {
	// 1. Drag-to-scroll for Primary Navigation
	const slider = document.querySelector('.nav-primary');
	if (slider) {
		let isDown = false;
		let startX;
		let scrollLeft;

		slider.addEventListener('mousedown', (e) => {
			isDown = true;
			slider.classList.add('cursor-grabbing');
			startX = e.pageX - slider.offsetLeft;
			scrollLeft = slider.scrollLeft;
		});

		slider.addEventListener('mouseleave', () => {
			isDown = false;
			slider.classList.remove('cursor-grabbing');
		});

		slider.addEventListener('mouseup', () => {
			isDown = false;
			slider.classList.remove('cursor-grabbing');
		});

		slider.addEventListener('mousemove', (e) => {
			if (!isDown) return;
			e.preventDefault();
			const x = e.pageX - slider.offsetLeft;
			const walk = (x - startX) * 2;
			slider.scrollLeft = scrollLeft - walk;
		});

		slider.querySelectorAll('a').forEach(link => {
			link.addEventListener('click', (e) => {
				if (slider.classList.contains('cursor-grabbing')) {
					e.preventDefault();
				}
			});
		});
	}

	// 2. Ticker Pause/Play Button
	const tickerToggle = document.querySelector('.gjn-ticker-toggle');
	const tickerTrack = document.querySelector('.animate-marquee');
	if (tickerToggle && tickerTrack) {
		const pauseIcon = tickerToggle.querySelector('.pause-icon');
		const playIcon = tickerToggle.querySelector('.play-icon');
		let isPaused = false;

		tickerToggle.addEventListener('click', () => {
			isPaused = !isPaused;
			if (isPaused) {
				tickerTrack.style.animationPlayState = 'paused';
				pauseIcon.classList.add('hidden');
				playIcon.classList.remove('hidden');
				tickerToggle.setAttribute('aria-label', 'Play ticker');
			} else {
				tickerTrack.style.animationPlayState = 'running';
				pauseIcon.classList.remove('hidden');
				playIcon.classList.add('hidden');
				tickerToggle.setAttribute('aria-label', 'Pause ticker');
			}
		});
	}
}

// Bind on DOMContentLoaded, unless the script itself is executed after the
// event has already fired (possible when an optimizer defers it) — then
// initialize immediately.
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', initUiScripts);
} else {
	initUiScripts();
}
