/** Render a decorative, fixed-size request indicator; callers own accessible loading announcements. */

/**
 * @returns A twenty-pixel spinner that respects reduced-motion preferences without affecting layout.
 */
export default function LoadingSpinner() {
    return (
        <svg
            width="20"
            height="20"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            aria-hidden="true"
            className="animate-spin motion-reduce:animate-none"
        >
            <circle cx="12" cy="12" r="9" opacity="0.25" />
            <path d="M12 3a9 9 0 0 1 9 9" strokeLinecap="round" />
        </svg>
    );
}
