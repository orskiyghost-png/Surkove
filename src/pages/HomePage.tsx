import Antigravity from '../components/Antigravity';

export default function HomePage() {
  return (
    <main className="home-page" aria-label="Surkove">
      <div className="home-page__background" aria-hidden="true">
        <Antigravity
          className="home-page__particles"
          colors={['#2d2d2d', '#777777', '#c3c3c3']}
          count={1500}
          magnetRadius={9}
          ringRadius={7}
          particleSize={0.9}
          autoAnimate
          particleShape="sphere"
          colorSpeed={0.09}
          interactive={false}
          clickBurst={false}
          lightMode
        />
      </div>
    </main>
  );
}
