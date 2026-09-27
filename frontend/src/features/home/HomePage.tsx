import ImageCarousel from '../../components/ImageCarousel';

const comment = {
  name: 'Author Name',
  role: 'Role',
  quote:
    'Nibh elit lacus mi elit, dui maecenas vestibulum cursus. Aliquet quam cursus tortor eu a. Enim, integer pellentesque sagittis lectus aliquam sed curus tortor ac, ac. Ornare quisque ullamcorper a eleifend fringilla turpis.',
};

export default function HomePage() {
  return (
    <>
      <section className="hero">
        <div className="container hero__inner">
          <div className="hero__content">
            <h1>Inscribete a el semillero SIIIS</h1>
            <p>
              Pellentesque convallis accumsan suscipit aliquet eu diam quis nulla turpis.
              lectus laoreet sed semper bibendum id. laculis purus malesuada porttitor a.
              Mi congue convallis consequat lectus lobortis. Aliquam semper purus vitae
              pulvinar. Quisque dolor magna, consequat ac lectus a, aliquet volutpat velit.
            </p>
            <button className="primary-btn">Inscribete</button>
          </div>

          <div className="hero__image-panel" aria-label="Grupo del semillero">
            <div className="hero__image">
              <div className="person person--1" />
              <div className="person person--2" />
              <div className="person person--3" />
              <div className="person person--4" />
              <div className="person person--5" />
              <div className="person person--6" />
            </div>
          </div>
        </div>
      </section>

      <section className="gallery-zone">
        <div className="container gallery-box">
          <ImageCarousel />
        </div>
      </section>

      <section className="title-section">
        <div className="container">
          <h2 className="display-title">SIIIS</h2>
        </div>
      </section>

      <section className="comments-section">
        <div className="container comments-wrap">
          <h3>Comentarios</h3>

          <div className="comment-card">
            <div className="comment-avatar">◔</div>
            <div className="comment-author">
              <strong>{comment.name}</strong>
              <span>{comment.role}</span>
            </div>
            <p>{comment.quote}</p>
            <div className="comment-tag">Zoomer</div>
          </div>
        </div>
      </section>
    </>
  );
}
