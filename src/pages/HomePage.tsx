import SiteHeader from '../components/SiteHeader';

export default function HomePage() {
  return (
    <>
      <SiteHeader />
      <main id="top">
        <section className="hero" aria-labelledby="hero-title">
          <div className="hero-copy">
            <p className="eyebrow">Surkove · 2026</p>
            <h1 className="display-type" id="hero-title">
              Пространство
              <br />
              для важного.
            </h1>
            <a className="button button-dark" href="#catalog">
              Исследовать
              <span aria-hidden="true">↘</span>
            </a>
          </div>
          <div className="hero-visual">
            <span className="visual-index">01 / 01</span>
            <span className="visual-caption">Визуальный образ</span>
          </div>
          <span className="hero-footnote">Объекты · образы · впечатления</span>
        </section>

        <section className="content-section" id="catalog" aria-labelledby="catalog-title">
          <div className="section-heading">
            <p className="eyebrow">01 — Коллекция</p>
            <h2 className="display-type" id="catalog-title">
              Каталог
            </h2>
          </div>
          <div className="placeholder-grid">
            <div className="placeholder placeholder-tall">
              <span>Место для каталога</span>
            </div>
            <div className="placeholder">
              <span>Место для каталога</span>
            </div>
            <div className="placeholder">
              <span>Место для каталога</span>
            </div>
          </div>
        </section>

        <section
          className="content-section photography-section"
          id="photography"
          aria-labelledby="photography-title"
        >
          <div className="section-heading">
            <p className="eyebrow">02 — Визуальный архив</p>
            <h2 className="display-type" id="photography-title">
              Фотографии
            </h2>
          </div>
          <div className="photo-placeholder">
            <span>Место для фотографий</span>
          </div>
        </section>
      </main>
      <footer className="site-footer">
        <a className="wordmark" href="#top">
          surkove
        </a>
        <span className="eyebrow">Независимый проект</span>
      </footer>
    </>
  );
}
