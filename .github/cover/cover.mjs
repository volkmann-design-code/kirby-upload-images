/**
 * Renders the cover images, the same on every run:
 *
 * - `.github/cover.png`: 1280 × 640 at 2× (2:1, the format of Kirby's
 *   plugin directory and of GitHub's social preview)
 * - `.github/cover-square.png`: 640 × 640 at 2× (1:1)
 *
 * Both show the real Panel of a fresh Kirby site (`site/`): the
 * repository's iPhone photo (`tests/fixtures/iphone.heic`), uploaded
 * through Kirby with this plugin, in the file view as the JPEG it became,
 * with capture time, camera and location in its fields; beside it the
 * photo before and after, as the upload measured it. Kirby is the
 * repository's dev dependency (`composer install`). Pinned Chromium
 * (Playwright) and pinned fonts (Fontsource): Inter replaces the Panel's
 * system font, JetBrains Mono its monospace one.
 *
 * Converting HEIC takes PHP with Imagick and libheif. Without them, PHP
 * runs in the repository's Docker image (`.github/imagick`, built when
 * missing), with the repository and the temp folder at the same paths.
 *
 *   npm ci && npx playwright install chromium && npm run cover
 */
import { execFileSync, spawn } from "node:child_process";
import { mkdirSync, mkdtempSync, readFileSync, realpathSync, rmSync, symlinkSync } from "node:fs";
import { createServer } from "node:net";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { chromium } from "playwright";

const here = dirname(fileURLToPath(import.meta.url));
const repo = join(here, "..", "..");
const site = join(here, "site");
const image = "kirby-upload-images-imagick";

// box: the most room the Panel's view may take in the cover (CSS pixels)
const covers = [
	{ template: "cover.html", out: "cover.png", width: 1280, height: 640, box: { width: 600, height: 560 } },
	{ template: "square.html", out: "cover-square.png", width: 640, height: 640, box: { width: 500, height: 430 } },
];

const font = (path, family, weight) => {
	const file = readFileSync(join(here, "node_modules", path)).toString("base64");
	return `@font-face { font-family: "${family}"; font-weight: ${weight}; src: url(data:font/woff2;base64,${file}) format("woff2"); }`;
};
const fonts = [
	font("@fontsource-variable/inter/files/inter-latin-wght-normal.woff2", "Inter Variable", "100 900"),
	font("@fontsource/jetbrains-mono/files/jetbrains-mono-latin-400-normal.woff2", "JetBrains Mono", 400),
].join("\n");

const freePort = () =>
	new Promise((resolve) => {
		const server = createServer().listen(0, "127.0.0.1", () => {
			const { port } = server.address();
			server.close(() => resolve(port));
		});
	});

// PHP here if it converts HEIC, else in Docker
const localHeic = (() => {
	try {
		execFileSync("php", ["-r", 'exit(class_exists("Imagick") && Imagick::queryFormats("HEI*") ? 0 : 1);']);
		return true;
	} catch {
		return false;
	}
})();

// content, accounts, sessions and media in a temp folder; the plugin is this repository
const data = realpathSync(mkdtempSync(join(tmpdir(), "kirby-upload-images-cover-")));
mkdirSync(join(data, "plugins"));
symlinkSync(repo, join(data, "plugins", "upload-images"));

const php = (args, options = {}) => {
	if (localHeic) {
		return { command: "php", args };
	}

	const root = realpathSync(repo);
	return {
		command: "docker",
		args: [
			"run",
			"--rm",
			...(options.name ? ["--name", options.name] : []),
			...(options.port ? ["-p", `127.0.0.1:${options.port}:${options.port}`] : []),
			"-e",
			`COVER_DATA=${data}`,
			...(options.port ? ["-e", `COVER_URL=http://127.0.0.1:${options.port}`] : []),
			"-v",
			`${root}:${root}`,
			"-v",
			`${data}:${data}`,
			"-w",
			site,
			image,
			"php",
			...args,
		],
	};
};

if (!localHeic) {
	try {
		execFileSync("docker", ["image", "inspect", image], { stdio: "ignore" });
	} catch {
		execFileSync("docker", ["build", "-t", image, join(repo, ".github", "imagick")], { stdio: "inherit" });
	}
}

const env = { ...process.env, COVER_DATA: data };
const seed = php([join(site, "index.php"), "seed"]);
const photo = JSON.parse(execFileSync(seed.command, seed.args, { env }).toString().trim().split("\n").pop());

const port = await freePort();
const base = `http://127.0.0.1:${port}`;
const name = `kirby-upload-images-cover-${port}`;
const serve = php(["-S", `${localHeic ? "127.0.0.1" : "0.0.0.0"}:${port}`, "-t", site, join(repo, "kirby", "router.php")], {
	name,
	port,
});
const server = spawn(serve.command, serve.args, { env, stdio: "ignore" });
const browser = await chromium.launch({ args: ["--font-render-hinting=none"] });

try {
	for (let i = 0; ; i++) {
		try {
			await fetch(`${base}/panel/login`);
			break;
		} catch (error) {
			if (i === 100) throw error;
			await new Promise((resolve) => setTimeout(resolve, 100));
		}
	}

	// logged in through the Panel's form
	const page = await browser.newPage({ deviceScaleFactor: 2, viewport: { width: 1000, height: 1200 } });
	await page.goto(`${base}/panel/login`);
	await page.fill('input[type="email"]', "mara@example.com");
	await page.fill('input[type="password"]', "cover-password");
	await page.click('button[type="submit"]');
	await page.waitForURL(/panel\/site/);

	// the converted photo's file view, with Inter as the Panel's font
	await page.goto(`${base}/panel/${photo.path}`);
	await page.addStyleTag({
		content: `${fonts}
			:root { --font-sans: "Inter Variable", sans-serif; --font-mono: "JetBrains Mono", monospace; }`,
	});
	await page.locator(".k-file-preview img").waitFor();
	await page.evaluate(async () => {
		await document.fonts.ready;
		await Promise.all([...document.images].map((img) => img.decode().catch(() => {})));
		document.activeElement?.blur();
		// the file's URL holds this run's port and media hash: not the same twice
		for (const term of document.querySelectorAll(".k-file-preview-details dt")) {
			if (term.textContent.trim() === "Url") {
				term.parentElement.remove();
			}
		}
	});

	// the view from its title to the last field, with some of the Panel around
	const pad = 28;
	const header = await page.locator(".k-file-view .k-header").boundingBox();
	const preview = await page.locator(".k-file-preview").boundingBox();
	const last = await page.locator(".k-field-name-location").boundingBox();
	const clip = {
		x: preview.x - pad,
		y: header.y - pad,
		width: preview.width + 2 * pad,
		height: last.y + last.height - header.y + 2 * pad,
	};
	const view = await page.screenshot({ clip, animations: "disabled" });

	// sizes as the Panel shows them
	const kb = (bytes) => `${Math.round(bytes / 1024)} KB`;
	const info = (file) => `${file.width} × ${file.height} · ${kb(file.size)}`;

	for (const cover of covers) {
		const scale = Math.min(cover.box.width / clip.width, cover.box.height / clip.height);
		const html = readFileSync(join(here, cover.template), "utf8")
			.replace("/*{{fonts}}*/", fonts)
			.replace("{{view}}", `data:image/png;base64,${view.toString("base64")}`)
			.replace("{{width}}", (clip.width * scale).toFixed(1))
			.replaceAll("{{beforeName}}", photo.before.name)
			.replaceAll("{{beforeInfo}}", info(photo.before))
			.replaceAll("{{afterName}}", photo.after.name)
			.replaceAll("{{afterInfo}}", info(photo.after));

		const shot = await browser.newPage({ deviceScaleFactor: 2, viewport: { width: cover.width, height: cover.height } });
		await shot.setContent(html);
		await shot.evaluate(() => document.fonts.ready);
		await shot.screenshot({ path: join(here, "..", cover.out), animations: "disabled" });
		console.log(`.github/${cover.out}`);
	}

	console.log(`Kirby ${JSON.parse(readFileSync(join(repo, "kirby", "composer.json"), "utf8")).version}`);
} finally {
	await browser.close();
	if (localHeic) {
		server.kill();
	} else {
		try {
			execFileSync("docker", ["stop", name], { stdio: "ignore" });
		} catch {
			server.kill();
		}
	}
	rmSync(data, { recursive: true, force: true });
}
