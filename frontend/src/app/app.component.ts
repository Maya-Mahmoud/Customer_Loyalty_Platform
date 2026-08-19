import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';

/**
 * Nothing but the router outlet: the authenticated chrome lives in
 * ShellComponent, and the login screen deliberately has none.
 */
@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet],
  templateUrl: './app.component.html',
  styleUrl: './app.component.css',
})
export class AppComponent {}
