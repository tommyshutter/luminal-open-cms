/**
 * @file SITE-ROOT/js/mobile-nav-integration.js
 * @version 2025.08.06.15.30.02 
 * @description Mobile navigation JavaScript integration for existing Luminal CMS system.
 * Works with the existing menu structure and $settings object configuration.
 */

(function() {
    'use strict';
    
    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMobileNavigation);
    } else {
        initMobileNavigation();
    }
    
    function initMobileNavigation() {
        
        // Get existing menu elements
        const menuToggle = document.querySelector('.menu-toggle');
        const mobileMenu = document.querySelector('.main-navigation');
        const menuLinks = document.querySelectorAll('.main-navigation a');
        const body = document.body;
        const header = document.querySelector('.site-header');
        
        // Validate required elements exist
        if (!menuToggle) {
            console.warn('Mobile Navigation: .menu-toggle button not found');
            return;
        }
        
        if (!mobileMenu) {
            console.warn('Mobile Navigation: .main-navigation not found');
            return;
        }
        
        // State management
        let isMenuOpen = false;
        let scrollPosition = 0;
        
        // Initialize ARIA attributes
        function initializeARIA() {
            menuToggle.setAttribute('aria-expanded', 'false');
            menuToggle.setAttribute('aria-controls', 'main-navigation');
            menuToggle.setAttribute('aria-label', 'Open navigation menu');
            
            mobileMenu.setAttribute('id', 'main-navigation');
            mobileMenu.setAttribute('aria-hidden', 'true');
            
            // Add role and labels if not present
            if (!mobileMenu.getAttribute('role')) {
                mobileMenu.setAttribute('role', 'navigation');
            }
            if (!mobileMenu.getAttribute('aria-label')) {
                mobileMenu.setAttribute('aria-label', 'Main navigation');
            }
        }
        
        // Toggle mobile menu
        function toggleMobileMenu(event) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }
            
            if (isMenuOpen) {
                closeMobileMenu();
            } else {
                openMobileMenu();
            }
        }
        
        // Open mobile menu
        function openMobileMenu() {
            if (isMenuOpen) return;
            
            // Store current scroll position
            scrollPosition = window.pageYOffset;
            
            // Add active classes
            body.classList.add('mobile-menu-active');
            isMenuOpen = true;
            
            // Update ARIA attributes
            menuToggle.setAttribute('aria-expanded', 'true');
            menuToggle.setAttribute('aria-label', 'Close navigation menu');
            mobileMenu.setAttribute('aria-hidden', 'false');
            
            // Focus management - focus first menu item after animation
            setTimeout(() => {
                if (menuLinks.length > 0) {
                    menuLinks[0].focus();
                }
            }, 300);
            
            // Add event listeners
            document.addEventListener('keydown', handleKeyDown);
            mobileMenu.addEventListener('click', handleOverlayClick);
            
            // Dispatch custom event
            dispatchCustomEvent('mobileMenuOpened');
            
            // Debug log
            console.log('Mobile Navigation: Menu opened');
        }
        
        // Close mobile menu
        function closeMobileMenu() {
            if (!isMenuOpen) return;
            
            // Remove active classes
            body.classList.remove('mobile-menu-active');
            isMenuOpen = false;
            
            // Update ARIA attributes
            menuToggle.setAttribute('aria-expanded', 'false');
            menuToggle.setAttribute('aria-label', 'Open navigation menu');
            mobileMenu.setAttribute('aria-hidden', 'true');
            
            // Return focus to menu toggle
            menuToggle.focus();
            
            // Remove event listeners
            document.removeEventListener('keydown', handleKeyDown);
            mobileMenu.removeEventListener('click', handleOverlayClick);
            
            // Dispatch custom event
            dispatchCustomEvent('mobileMenuClosed');
            
            // Debug log
            console.log('Mobile Navigation: Menu closed');
        }
        
        // Handle keyboard events
        function handleKeyDown(event) {
            switch (event.key) {
                case 'Escape':
                    closeMobileMenu();
                    break;
                    
                case 'Tab':
                    handleTabNavigation(event);
                    break;
            }
        }
        
        // Handle tab navigation within menu
        function handleTabNavigation(event) {
            if (!isMenuOpen) return;
            
            const focusableElements = [menuToggle, ...menuLinks];
            const currentIndex = focusableElements.indexOf(document.activeElement);
            
            if (event.shiftKey) {
                // Shift + Tab (backwards)
                if (currentIndex <= 0) {
                    event.preventDefault();
                    focusableElements[focusableElements.length - 1].focus();
                }
            } else {
                // Tab (forwards)
                if (currentIndex >= focusableElements.length - 1) {
                    event.preventDefault();
                    focusableElements[0].focus();
                }
            }
        }
        
        // Handle overlay click (close menu when clicking background)
        function handleOverlayClick(event) {
            if (event.target === mobileMenu) {
                closeMobileMenu();
            }
        }
        
        // Handle window resize
        function handleWindowResize() {
            // Close mobile menu if window becomes wide enough for desktop navigation
            if (window.innerWidth > 768 && isMenuOpen) {
                closeMobileMenu();
            }
        }
        
        // Handle menu link clicks
        function handleMenuLinkClick(event) {
            const link = event.currentTarget;
            const href = link.getAttribute('href');
            
            // Close menu after a short delay to allow navigation to begin
            setTimeout(() => {
                closeMobileMenu();
            }, 100);
            
            // Handle anchor links with smooth scrolling
            if (href && href.startsWith('#')) {
                event.preventDefault();
                const target = document.querySelector(href);
                
                if (target) {
                    closeMobileMenu();
                    
                    // Smooth scroll to target after menu closes
                    setTimeout(() => {
                        const headerHeight = header ? header.offsetHeight : 0;
                        const targetPosition = target.getBoundingClientRect().top + window.pageYOffset - headerHeight - 20;
                        
                        window.scrollTo({
                            top: targetPosition,
                            behavior: 'smooth'
                        });
                        
                        // Update browser history
                        if (history.pushState) {
                            history.pushState(null, null, href);
                        }
                    }, 300);
                }
            }
        }
        
        // Touch gesture support
        let touchStartY = 0;
        let touchEndY = 0;
        let touchStartTime = 0;
        
        function handleTouchStart(event) {
            if (!isMenuOpen) return;
            
            touchStartY = event.changedTouches[0].screenY;
            touchStartTime = Date.now();
        }
        
        function handleTouchEnd(event) {
            if (!isMenuOpen) return;
            
            touchEndY = event.changedTouches[0].screenY;
            const touchTime = Date.now() - touchStartTime;
            const swipeDistance = touchStartY - touchEndY;
            const swipeSpeed = Math.abs(swipeDistance) / touchTime;
            
            // Swipe up to close menu (minimum distance and speed)
            if (swipeDistance > 100 && swipeSpeed > 0.3) {
                closeMobileMenu();
            }
        }
        
        // Custom event dispatcher
        function dispatchCustomEvent(eventName, detail = {}) {
            const customEvent = new CustomEvent(eventName, {
                detail: detail,
                bubbles: true,
                cancelable: true
            });
            document.dispatchEvent(customEvent);
        }
        
        // Initialize event listeners
        function initializeEventListeners() {
            // Menu toggle button
            menuToggle.addEventListener('click', toggleMobileMenu);
            
            // Menu links
            menuLinks.forEach(link => {
                link.addEventListener('click', handleMenuLinkClick);
            });
            
            // Window resize
            window.addEventListener('resize', debounce(handleWindowResize, 250));
            
            // Touch gestures
            mobileMenu.addEventListener('touchstart', handleTouchStart, { passive: true });
            mobileMenu.addEventListener('touchend', handleTouchEnd, { passive: true });
        }
        
        // Debounce utility function
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
        
        // Page visibility change handler (close menu when tab becomes hidden)
        function handleVisibilityChange() {
            if (document.hidden && isMenuOpen) {
                closeMobileMenu();
            }
        }
        
        // Initialize everything
        function initialize() {
            initializeARIA();
            initializeEventListeners();
            
            // Add visibility change listener
            document.addEventListener('visibilitychange', handleVisibilityChange);
            
            // Add CSS class to indicate JS is loaded
            body.classList.add('mobile-nav-js-loaded');
            
            console.log('Mobile Navigation: Initialized successfully');
            console.log('Mobile Navigation: Menu toggle found -', !!menuToggle);
            console.log('Mobile Navigation: Navigation menu found -', !!mobileMenu);
            console.log('Mobile Navigation: Menu links found -', menuLinks.length);
        }
        
        // Public API for external scripts
        window.MobileNavigation = {
            open: openMobileMenu,
            close: closeMobileMenu,
            toggle: toggleMobileMenu,
            isOpen: () => isMenuOpen
        };
        
        // Integration with existing YouTube API and other scripts
        if (window.addYouTubeApiCallback) {
            window.addYouTubeApiCallback(() => {
                console.log('Mobile Navigation: YouTube API integrated');
            });
        }
        
        // Run initialization
        initialize();
        
        // Handle potential dynamic content loading
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'childList') {
                    // Re-scan for menu links if DOM changes
                    const newLinks = document.querySelectorAll('.main-navigation a');
                    if (newLinks.length !== menuLinks.length) {
                        console.log('Mobile Navigation: Menu links updated, re-initializing...');
                        // Could re-initialize here if needed
                    }
                }
            });
        });
        
        // Observe changes to navigation
        observer.observe(mobileMenu, { childList: true, subtree: true });
    }
    
})();

// Expose event listeners for external integration
document.addEventListener('mobileMenuOpened', function(event) {
    console.log('Mobile menu opened event fired', event.detail);
});

document.addEventListener('mobileMenuClosed', function(event) {
    console.log('Mobile menu closed event fired', event.detail);
});