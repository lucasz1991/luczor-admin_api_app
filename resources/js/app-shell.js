const DESKTOP_SIDEBAR_EXPAND_DELAY = 750;
const DESKTOP_SIDEBAR_COLLAPSE_DELAY = 1500;
const MOBILE_BREAKPOINT = 1024;

export function registerLuczorShell(Alpine) {
    Alpine.data('luczorShell', () => ({
        mobileOpen: false,
        desktopExpanded: false,
        isMobile: window.innerWidth < MOBILE_BREAKPOINT,
        profileOpen: false,
        expandTimer: null,
        collapseTimer: null,
        layoutTimer: null,
        touchStart: null,
        touchCurrent: null,
        touchMoved: false,
        suppressNextClick: false,
        suppressClickTimer: null,

        init() {
            this.handleResize();
            this.syncDocumentState();
        },

        destroy() {
            this.clearSidebarTimers();
            window.clearTimeout(this.layoutTimer);
            window.clearTimeout(this.suppressClickTimer);
            document.documentElement.classList.remove('luczor-mobile-nav-open');
        },

        handleResize() {
            const nextIsMobile = window.innerWidth < MOBILE_BREAKPOINT;
            this.isMobile = nextIsMobile;

            if (!nextIsMobile) {
                this.mobileOpen = false;
            } else {
                this.desktopExpanded = false;
            }

            this.syncDocumentState();
        },

        toggleMobile() {
            if (!this.isMobile) {
                return;
            }

            this.mobileOpen ? this.closeMobile(true) : this.openMobile();
        },

        openMobile() {
            if (!this.isMobile) {
                return;
            }

            this.profileOpen = false;
            this.mobileOpen = true;
            this.syncDocumentState();

            this.$nextTick(() => {
                this.$refs.sidebar?.querySelector('.luczor-nav-link')?.focus({ preventScroll: true });
            });
        },

        closeMobile(restoreFocus = false) {
            const wasOpen = this.mobileOpen;
            this.mobileOpen = false;
            this.syncDocumentState();

            if (restoreFocus && wasOpen) {
                this.$nextTick(() => this.$refs.mobileToggle?.focus({ preventScroll: true }));
            }
        },

        closeTransientUi(restoreFocus = false) {
            const restoreProfileFocus = restoreFocus && this.profileOpen;
            const restoreMobileFocus = restoreFocus && this.mobileOpen;
            this.profileOpen = false;
            this.closeMobile(restoreMobileFocus);

            if (restoreProfileFocus) {
                this.$nextTick(() => this.$refs.profileTrigger?.focus({ preventScroll: true }));
            }
        },

        syncDocumentState() {
            document.documentElement.classList.toggle(
                'luczor-mobile-nav-open',
                this.isMobile && this.mobileOpen,
            );
        },

        clearSidebarTimers() {
            window.clearTimeout(this.expandTimer);
            window.clearTimeout(this.collapseTimer);
            this.expandTimer = null;
            this.collapseTimer = null;
        },

        scheduleDesktopExpansion(expanded) {
            if (this.isMobile) {
                return;
            }

            this.clearSidebarTimers();
            const delay = expanded
                ? DESKTOP_SIDEBAR_EXPAND_DELAY
                : DESKTOP_SIDEBAR_COLLAPSE_DELAY;
            const timerName = expanded ? 'expandTimer' : 'collapseTimer';

            this[timerName] = window.setTimeout(() => {
                this.desktopExpanded = expanded;
                this[timerName] = null;
                this.queueLayoutResize();
            }, delay);
        },

        queueLayoutResize() {
            window.clearTimeout(this.layoutTimer);
            this.layoutTimer = window.setTimeout(() => {
                this.layoutTimer = null;
                window.dispatchEvent(new Event('resize'));
            }, 560);
        },

        expandDesktopForFocus() {
            if (this.isMobile) {
                return;
            }

            this.clearSidebarTimers();
            this.desktopExpanded = true;
            this.queueLayoutResize();
        },

        handleSidebarFocusOut(event) {
            if (this.isMobile || event.currentTarget.contains(event.relatedTarget)) {
                return;
            }

            this.scheduleDesktopExpansion(false);
        },

        handleTouchStart(event) {
            if (!this.isMobile || event.touches.length !== 1) {
                this.cancelTouchGesture();
                return;
            }

            const target = event.target instanceof Element ? event.target : null;
            if (target?.closest('input, textarea, select, [role="dialog"], [data-no-sidebar-swipe]')) {
                this.cancelTouchGesture();
                return;
            }

            const touch = event.touches[0];
            if (!this.mobileOpen && (touch.clientX > 28 || target?.closest('button, a'))) {
                this.cancelTouchGesture();
                return;
            }

            this.touchStart = { x: touch.clientX, y: touch.clientY };
            this.touchCurrent = { x: touch.clientX, y: touch.clientY };
            this.touchMoved = false;
        },

        handleTouchMove(event) {
            if (!this.touchStart || !this.isMobile || event.touches.length !== 1) {
                return;
            }

            const touch = event.touches[0];
            this.touchCurrent = { x: touch.clientX, y: touch.clientY };
            const deltaX = this.touchCurrent.x - this.touchStart.x;
            const deltaY = this.touchCurrent.y - this.touchStart.y;

            if (Math.abs(deltaX) > 10 && Math.abs(deltaX) > Math.abs(deltaY) * 1.15) {
                this.touchMoved = true;
                if (event.cancelable) {
                    event.preventDefault();
                }
            }
        },

        handleTouchEnd(event) {
            if (!this.touchStart || !this.isMobile || event.changedTouches.length !== 1) {
                this.cancelTouchGesture();
                return;
            }

            const touch = event.changedTouches[0];
            const deltaX = touch.clientX - this.touchStart.x;
            const deltaY = touch.clientY - this.touchStart.y;
            const threshold = Math.max(64, Math.min(110, window.innerWidth * 0.22));
            const horizontalGesture = this.touchMoved
                && Math.abs(deltaX) >= threshold
                && Math.abs(deltaX) >= Math.abs(deltaY) * 1.25;
            const shouldOpen = horizontalGesture && !this.mobileOpen && deltaX > 0;
            const shouldClose = horizontalGesture && this.mobileOpen && deltaX < 0;
            this.cancelTouchGesture();

            if (!shouldOpen && !shouldClose) {
                return;
            }

            this.suppressNextClick = true;
            window.clearTimeout(this.suppressClickTimer);
            this.suppressClickTimer = window.setTimeout(() => {
                this.suppressNextClick = false;
                this.suppressClickTimer = null;
            }, 500);

            if (shouldOpen) {
                this.openMobile();
            } else {
                this.closeMobile(true);
            }
        },

        cancelTouchGesture() {
            this.touchStart = null;
            this.touchCurrent = null;
            this.touchMoved = false;
        },

        handleCapturedClick(event) {
            if (!this.suppressNextClick) {
                return;
            }

            this.suppressNextClick = false;
            window.clearTimeout(this.suppressClickTimer);
            this.suppressClickTimer = null;
            event.preventDefault();
            event.stopImmediatePropagation();
        },
    }));
}
