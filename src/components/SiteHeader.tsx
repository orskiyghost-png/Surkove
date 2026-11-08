const navigation = [
  { label: 'Каталог', href: '#catalog' },
  { label: 'Фотографии', href: '#photography' },
];

export default function SiteHeader() {
  return (
    <header className="site-header">
      <a className="wordmark" href="#top" aria-label="Surkove, главная">
        surkove
      </a>
      <nav className="site-nav" aria-label="Основная навигация">
        {navigation.map((item) => (
          <a className="text-link" href={item.href} key={item.href}>
            {item.label}
          </a>
        ))}
      </nav>
    </header>
  );
}