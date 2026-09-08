import React from 'react';

/**
 * Global error boundary — catches render errors so users see a friendly
 * recovery screen instead of a blank page.
 */
export default class ErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = { error: null };
  }

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error, info) {
    // Surface to the console; wire to a logging endpoint later if needed.
    console.error('Unhandled UI error:', error, info);
  }

  render() {
    if (this.state.error) {
      return (
        <div className="flex min-h-screen items-center justify-center bg-gray-50 p-4">
          <div className="w-full max-w-md rounded-xl border border-red-100 bg-white p-6 text-center shadow-sm">
            <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-2xl">
              ⚠️
            </div>
            <h1 className="text-lg font-bold text-gray-900">Something went wrong</h1>
            <p className="mt-1 text-sm text-gray-500">
              An unexpected error occurred while rendering the page. Your data is safe.
            </p>
            <pre className="mt-3 max-h-32 overflow-auto rounded-lg bg-gray-50 p-3 text-left text-xs text-gray-500">
              {String(this.state.error?.message || this.state.error)}
            </pre>
            <div className="mt-4 flex justify-center gap-2">
              <button
                className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                onClick={() => this.setState({ error: null })}
              >
                Try again
              </button>
              <button
                className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                onClick={() => window.location.assign('/')}
              >
                Go to dashboard
              </button>
            </div>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}
