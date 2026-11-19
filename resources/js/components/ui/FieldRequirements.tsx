/** Share required-label presentation across fields and group legends in forms with optional inputs. */
import { createContext, useContext, type ReactNode } from 'react';

/** Forms opt into markers only when required and optional inputs coexist. */
const OptionalFieldsContext = createContext(false);

/** Required-marker policy for one form, independent of domain validation and submission. */
interface FieldRequirementsProps {
    /** True when the rendered form contains optional inputs; all-required forms omit markers. */
    hasOptionalFields: boolean;
    /** Shared fields and legends inheriting this policy. */
    children: ReactNode;
}

/**
 * @param props Form-level optionality and its field subtree.
 * @returns A presentation boundary that preserves native required validation semantics.
 */
export default function FieldRequirements({ hasOptionalFields, children }: FieldRequirementsProps) {
    return (
        <OptionalFieldsContext.Provider value={hasOptionalFields}>
            {children}
        </OptionalFieldsContext.Provider>
    );
}

/** Inputs for the shared decorative marker, also usable inside a fieldset legend. */
interface RequiredIndicatorProps {
    /** Whether this field or group requires a value according to domain validation. */
    required: boolean;
}

/**
 * @param props Domain requirement of this field or group.
 * @returns A decorative CSS asterisk in mixed forms, preserving the accessible label text.
 */
export function RequiredIndicator({ required }: RequiredIndicatorProps) {
    const hasOptionalFields = useContext(OptionalFieldsContext);
    return required && hasOptionalFields ? (
        <span aria-hidden="true" className="ui-required-indicator" />
    ) : null;
}
