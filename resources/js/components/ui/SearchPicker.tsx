/** Combine a shared search field with keyboard-operable local suggestions and caller-owned selection. */
import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import InputField from './InputField';

/** Searchable identity and label, deliberately free of domain transport or credentials. */
export interface SearchChoice {
    /** Stable identity returned on selection. */
    id: number;
    /** Visible and accessible suggestion label. */
    label: string;
}

/** Caller-owned query, already filtered choices and selection effect. */
interface SearchPickerProps {
    /** Accessible name of the search field. */
    label: string;
    /** Current raw query; the caller owns matching and pagination. */
    query: string;
    /** Available suggestions for the current query. */
    choices: SearchChoice[];
    /** Prevent interaction during an owned pending request. */
    disabled?: boolean;
    /** Update the raw query without navigation. */
    onQueryChange: (query: string) => void;
    /** Choose an identity, such as revealing and focusing its existing card. */
    onSelect: (choice: SearchChoice) => void;
}

/**
 * @param props Accessible field, query, local choices and caller-owned effects.
 * @returns A combobox opening on focus, with pointer selection and arrows/Enter/Escape support.
 */
export default function SearchPicker({
    label,
    query,
    choices,
    disabled = false,
    onQueryChange,
    onSelect,
}: SearchPickerProps) {
    const popupId = useId();
    const popup = useRef<HTMLUListElement>(null);
    const [expanded, setExpanded] = useState(false);
    const [active, setActive] = useState(-1);
    const open = expanded && !disabled;
    const activeChoice = choices[active];

    useEffect(() => {
        const list = popup.current;
        const option = activeChoice
            ? document.getElementById(`${popupId}-${activeChoice.id}`)
            : null;
        if (!open || !list || !option) return;
        if (option.offsetTop < list.scrollTop) list.scrollTop = option.offsetTop;
        else if (option.offsetTop + option.offsetHeight > list.scrollTop + list.clientHeight)
            list.scrollTop = option.offsetTop + option.offsetHeight - list.clientHeight;
    }, [open, activeChoice, popupId]);

    /** Commit a suggestion without moving focus through the popup's rows. */
    function choose(choice: SearchChoice): void {
        setExpanded(false);
        setActive(-1);
        onSelect(choice);
    }

    /** Keep keyboard focus on the input while the active-descendant follows the list. */
    function keyboard(event: KeyboardEvent<HTMLInputElement>): void {
        if (event.key === 'Escape') {
            event.preventDefault();
            setExpanded(false);
            setActive(-1);
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            setExpanded(true);
            const next =
                event.key === 'ArrowDown'
                    ? active + 1
                    : active < 0
                      ? choices.length - 1
                      : active - 1;
            setActive(Math.max(0, Math.min(choices.length - 1, next)));
        } else if (event.key === 'Enter' && open && activeChoice) {
            event.preventDefault();
            choose(activeChoice);
        }
    }

    return (
        <div className="ui-search-picker">
            <InputField
                label={label}
                labelHidden
                placeholder={label}
                type="search"
                role="combobox"
                aria-autocomplete="list"
                aria-expanded={open}
                aria-controls={open ? popupId : undefined}
                aria-activedescendant={
                    open && activeChoice ? `${popupId}-${activeChoice.id}` : undefined
                }
                disabled={disabled}
                value={query}
                onFocus={() => setExpanded(true)}
                onClick={() => setExpanded(true)}
                onBlur={() => {
                    setExpanded(false);
                    setActive(-1);
                }}
                onKeyDown={keyboard}
                onChange={(event) => {
                    onQueryChange(event.target.value);
                    setExpanded(true);
                    setActive(-1);
                }}
            />
            {open && (
                <ul
                    ref={popup}
                    id={popupId}
                    role="listbox"
                    aria-label={`${label} suggestions`}
                    className="ui-search-suggestions"
                >
                    {choices.map((choice, index) => (
                        <li
                            key={choice.id}
                            id={`${popupId}-${choice.id}`}
                            role="option"
                            aria-selected={active === index}
                            className="ui-search-suggestion"
                            onPointerDown={(event) => event.preventDefault()}
                            onMouseDown={(event) => event.preventDefault()}
                            onMouseMove={() => setActive(index)}
                            onClick={() => choose(choice)}
                        >
                            {choice.label}
                        </li>
                    ))}
                    {choices.length === 0 && (
                        <li role="presentation" className="ui-help">
                            No matches.
                        </li>
                    )}
                </ul>
            )}
        </div>
    );
}
