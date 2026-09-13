import { Link } from 'jsx-email'

/**
 * El botón naranja, igual que el del correo que manda el BFF.
 *
 * Es un `<a>` con relleno y no un `<button>`: en un correo no hay JavaScript, y
 * los clientes que sí pintan botones los pintan como quieren.
 */
export function Button({ href, children }: { href: string; children: React.ReactNode }) {
    return (
        <Link
            href={href}
            style={{
                backgroundColor: '#FF6744',
                borderRadius: '8px',
                color: '#ffffff',
                display: 'inline-block',
                fontSize: '15px',
                fontWeight: 'bold',
                padding: '14px 36px',
                textDecoration: 'none',
                textAlign: 'center' as const,
            }}
        >
            {children}
        </Link>
    )
}
