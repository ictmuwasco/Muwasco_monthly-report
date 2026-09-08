import { useState } from 'react';

/** Minimal toast queue. Provide <Toaster/> once in the app. */
export function useToasts() {
  const [toasts, setToasts] = useState([]);

  const push = (message, type = 'success') => {
    const id = Date.now() + Math.random().toString(36).slice(2);
    setToasts((t) => [...t, { id, message, type }]);
    setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), 4000);
  };

  return { toasts, push };
}

export function Toaster({ toasts }) {
  return (
    <div className="fixed bottom-4 right-4 z-50 space-y-2">
      {toasts.map((t) => (
        <div
          key={t.id}
          className={`rounded-md px-4 py-3 text-sm text-white shadow-lg ${
            t.type === 'error' ? 'bg-red-600' : t.type === 'info' ? 'bg-blue-600' : 'bg-green-600'
          }`}
        >
          {t.message}
        </div>
      ))}
    </div>
  );
}