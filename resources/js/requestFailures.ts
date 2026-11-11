/** Classify response-less browser transport failures without consuming or displaying sensitive diagnostics. */

/**
 * @param exception Error emitted after Inertia has handled HTTP responses and cancellations.
 * @returns Whether a browser transport failed without a response; programming errors remain unhandled.
 */
export function isTransportFailure(exception: unknown): exception is Record<string, unknown> {
    return (
        typeof exception === 'object' &&
        exception !== null &&
        'isAxiosError' in exception &&
        exception.isAxiosError === true &&
        'code' in exception &&
        typeof exception.code === 'string' &&
        ['ERR_NETWORK', 'ECONNABORTED', 'ETIMEDOUT'].includes(exception.code) &&
        'request' in exception &&
        exception.request instanceof XMLHttpRequest &&
        (!('response' in exception) || exception.response === undefined)
    );
}
