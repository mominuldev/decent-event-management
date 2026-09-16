import { useCallback, useRef, useState, type DragEvent } from 'react';

/**
 * Turns any element into a drop target for files from the desktop.
 *
 * Spread `dropProps` onto the element and render whatever "drop it here"
 * affordance you like while `isOver` is true. Two details are what make this
 * worth a hook rather than four inline handlers:
 *
 * - `dragleave` fires every time the pointer crosses a *child* boundary, so a
 *   naive `setOver(false)` flickers the overlay off and on as the file passes
 *   over each thumbnail. A depth counter (enter++ / leave--) only clears when
 *   the pointer has really left the target.
 * - Only a drag carrying `Files` counts. Dragging selected text or an image
 *   out of the page itself also fires these events, and showing an upload
 *   overlay for that would promise something the drop cannot deliver.
 *
 * No type filtering happens here: the server decides what a file is from its
 * magic bytes, so the caller gets every dropped file and the API's own 422
 * names the ones it refused.
 *
 * While `disabled` (no permission, or an upload already running) the drop is
 * still swallowed — the browser's default for a dropped file is to navigate
 * to it, which would replace the whole SPA with a JPEG.
 */
export function useFileDrop({ onFiles, disabled = false }: { onFiles: (files: File[]) => void; disabled?: boolean }) {
    const [isOver, setOver] = useState(false);
    const depth = useRef(0);

    const carriesFiles = (e: DragEvent) => Array.from(e.dataTransfer?.types ?? []).includes('Files');

    const onDragEnter = useCallback((e: DragEvent) => {
        if (disabled || !carriesFiles(e)) return;
        e.preventDefault();
        depth.current += 1;
        setOver(true);
    }, [disabled]);

    const onDragOver = useCallback((e: DragEvent) => {
        if (!carriesFiles(e)) return;
        // Without this the browser treats the drop as navigation and opens the file.
        e.preventDefault();
        e.dataTransfer.dropEffect = disabled ? 'none' : 'copy';
    }, [disabled]);

    const onDragLeave = useCallback((e: DragEvent) => {
        if (disabled || !carriesFiles(e)) return;
        depth.current = Math.max(0, depth.current - 1);
        if (depth.current === 0) setOver(false);
    }, [disabled]);

    const onDrop = useCallback((e: DragEvent) => {
        if (!carriesFiles(e)) return;
        e.preventDefault();
        depth.current = 0;
        setOver(false);
        if (disabled) return;
        const files = Array.from(e.dataTransfer.files);
        if (files.length > 0) onFiles(files);
    }, [disabled, onFiles]);

    return { isOver, dropProps: { onDragEnter, onDragOver, onDragLeave, onDrop } };
}
